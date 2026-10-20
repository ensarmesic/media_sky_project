<?php

namespace App\Models;

use App\Enums\CaseItemStatus;
use App\Enums\CaseItemType;
use App\Enums\CaseOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['administrative_case_id', 'name', 'description', 'type', 'status', 'outcome', 'responsible_user_id', 'institution_id', 'recipient', 'start_date', 'submitted_at', 'response_at', 'due_date', 'completed_at', 'response_reminder_interval', 'response_reminded_at', 'deadline_reminded_at', 'overdue_reminded_at', 'is_blocked', 'blocked_reason', 'progress_weight', 'position', 'is_required_for_closure', 'is_active', 'notes', 'created_by'])]
class AdministrativeCaseItem extends Model
{
    protected function casts(): array
    {
        return ['type' => CaseItemType::class, 'status' => CaseItemStatus::class, 'outcome' => CaseOutcome::class, 'start_date' => 'date', 'submitted_at' => 'date', 'response_at' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime', 'response_reminded_at' => 'datetime', 'deadline_reminded_at' => 'datetime', 'overdue_reminded_at' => 'datetime', 'is_blocked' => 'boolean', 'progress_weight' => 'decimal:2', 'is_required_for_closure' => 'boolean', 'is_active' => 'boolean'];
    }

    public function isOpen(): bool
    {
        return $this->is_active && ! in_array($this->status, [CaseItemStatus::Completed, CaseItemStatus::NotApplicable], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->isPast();
    }

    public function isDueSoon(): bool
    {
        return $this->isOpen() && $this->due_date !== null
            && $this->due_date->between(now()->startOfDay(), now()->addDays((int) config('projectflow.reminders.due_soon_days', 7))->endOfDay());
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

    public function activities(): HasMany
    {
        return $this->hasMany(AdministrativeCaseItemActivity::class);
    }

    /** Link koji otvara projekat s otvorenim detaljima ovog zadatka. */
    public function url(): string
    {
        return route('projects.show', ['project' => $this->administrativeCase->project_id, 'zadatak' => $this->id]);
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
