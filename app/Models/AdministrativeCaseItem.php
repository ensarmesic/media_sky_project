<?php

namespace App\Models;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Enums\CaseItemType;
use App\Enums\CaseOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Zadatak predmeta. Tehnički naziv ostaje "stavka administrativnog predmeta",
 * u interfejsu se zove "zadatak".
 */
#[Fillable(['administrative_case_id', 'name', 'description', 'type', 'status', 'priority', 'outcome', 'responsible_user_id', 'institution_id', 'recipient', 'start_date', 'submitted_at', 'response_at', 'due_date', 'completed_at', 'response_reminder_interval', 'response_reminded_at', 'deadline_reminded_at', 'overdue_reminded_at', 'is_blocked', 'blocked_reason', 'progress_weight', 'position', 'is_required_for_closure', 'is_active', 'notes', 'created_by'])]
class AdministrativeCaseItem extends Model
{
    protected $attributes = [
        'status' => 'new',
        'priority' => 'medium',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        // zavrseno_at se postavlja automatski kad status pređe u "Završeno".
        static::saving(function (AdministrativeCaseItem $item): void {
            if ($item->isDirty('status')) {
                $item->completed_at = $item->status === CaseItemStatus::Completed ? ($item->completed_at ?? now()) : null;
            }
        });
    }

    protected function casts(): array
    {
        return ['type' => CaseItemType::class, 'status' => CaseItemStatus::class, 'priority' => CaseItemPriority::class, 'outcome' => CaseOutcome::class, 'start_date' => 'date', 'submitted_at' => 'date', 'response_at' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime', 'response_reminded_at' => 'datetime', 'deadline_reminded_at' => 'datetime', 'overdue_reminded_at' => 'datetime', 'is_blocked' => 'boolean', 'progress_weight' => 'decimal:2', 'is_required_for_closure' => 'boolean', 'is_active' => 'boolean'];
    }

    /** Aktivan = nije uklonjen i nije završen/otkazan. */
    public function isOpen(): bool
    {
        return $this->is_active && ! $this->status->isClosed();
    }

    /** Kasni = rok je prije današnjeg dana, a zadatak nije završen ni otkazan. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }

    public function isDueSoon(): bool
    {
        return $this->isOpen() && $this->due_date !== null
            && $this->due_date->between(today(), today()->addDays((int) config('projectflow.reminders.due_soon_days', 7)));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotIn('status', CaseItemStatus::closedValues());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', today());
    }

    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Stari zapisi istorije (prije centralnog loga aktivnosti). */
    public function activities(): HasMany
    {
        return $this->hasMany(AdministrativeCaseItemActivity::class);
    }

    /** Centralni log aktivnosti (project_activities sa ovim zadatkom kao subjektom). */
    public function activityLog(): MorphMany
    {
        return $this->morphMany(ProjectActivity::class, 'subject');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /** Direktan link na detalj zadatka. */
    public function url(): string
    {
        return route('tasks.show', $this->id);
    }

    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
    }

    /** Dokumenti (vanjski linkovi / protokol) dodatno povezani sa stavkom preko pivot tabele. */
    public function linkedDocumentReferences(): BelongsToMany
    {
        return $this->belongsToMany(DocumentReference::class, 'document_reference_items');
    }
}
