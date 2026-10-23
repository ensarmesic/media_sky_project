<?php

namespace App\Models;

use App\Enums\CaseItemPriority;
use App\Enums\CaseItemStatus;
use App\Enums\CaseItemType;
use App\Enums\CaseOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Stavka postupka (u interfejsu "Stavka"; u kodu stavka administrativnog predmeta).
 * Institucija/primalac = institution_id ili slobodan tekst recipient.
 *
 * @property int $id
 * @property int $administrative_case_id
 * @property string $name
 * @property string|null $description
 * @property CaseItemType $type
 * @property CaseItemStatus $status
 * @property CaseOutcome|null $outcome
 * @property int|null $responsible_user_id
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $completed_at
 * @property string $progress_weight
 * @property int $position
 * @property bool $is_required_for_closure
 * @property bool $is_active
 * @property string|null $notes
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $institution_id
 * @property string|null $recipient
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $response_at
 * @property int|null $response_reminder_interval
 * @property CarbonImmutable|null $response_reminded_at
 * @property bool $is_blocked
 * @property string|null $blocked_reason
 * @property CarbonImmutable|null $deadline_reminded_at
 * @property CarbonImmutable|null $overdue_reminded_at
 * @property CaseItemPriority $priority
 * @property-read Institution|null $institution
 * @property-read User|null $responsible
 */
class AdministrativeCaseItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['administrative_case_id', 'name', 'description', 'type', 'status', 'priority', 'outcome', 'responsible_user_id', 'institution_id', 'recipient', 'start_date', 'submitted_at', 'response_at', 'due_date', 'completed_at', 'response_reminder_interval', 'response_reminded_at', 'deadline_reminded_at', 'overdue_reminded_at', 'is_blocked', 'blocked_reason', 'progress_weight', 'position', 'is_required_for_closure', 'is_active', 'notes', 'created_by'];

    /** @var array<string, string> */
    protected $casts = ['type' => CaseItemType::class, 'status' => CaseItemStatus::class, 'priority' => CaseItemPriority::class, 'outcome' => CaseOutcome::class, 'start_date' => 'date', 'submitted_at' => 'date', 'response_at' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime', 'response_reminded_at' => 'datetime', 'deadline_reminded_at' => 'datetime', 'overdue_reminded_at' => 'datetime', 'is_blocked' => 'boolean', 'progress_weight' => 'decimal:2', 'is_required_for_closure' => 'boolean', 'is_active' => 'boolean'];

    protected $attributes = [
        'status' => 'not_started',
        'priority' => 'medium',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (AdministrativeCaseItem $item): void {
            if (! $item->isDirty('status')) {
                return;
            }
            // Prelaz u "Predato" postavlja datum predaje ako je prazan.
            if ($item->status === CaseItemStatus::Submitted && $item->submitted_at === null) {
                $item->submitted_at = local_today();
            }
            // Prelaz u "Završeno" postavlja datum odgovora (ako je prazan) i vrijeme završetka.
            if ($item->status === CaseItemStatus::Completed) {
                $item->response_at ??= local_today();
                $item->completed_at ??= now();
            } else {
                $item->completed_at = null;
            }
        });
    }

    /** Aktivna = nije uklonjena i nije završena/otkazana. */
    public function isOpen(): bool
    {
        return $this->is_active && ! $this->status->isClosed();
    }

    /** Kasni = rok je prošao (prije danas), a stavka nije završena ni otkazana. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(local_today());
    }

    /** Rok je danas ili u narednih $days dana. */
    public function isDueWithin(int $days): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->between(local_today(), local_today()->addDays($days));
    }

    public function isDueSoon(): bool
    {
        return $this->isDueWithin((int) config('projectflow.reminders.due_soon_days', 3));
    }

    /** Broj dana do roka (negativno = kasni). */
    public function daysUntilDue(): ?int
    {
        return $this->due_date === null ? null : (int) local_today()->diffInDays($this->due_date, false);
    }

    /** Dana čeka odgovor = danas − datum predaje (samo za "Čeka odgovor"). */
    public function daysWaiting(): ?int
    {
        if ($this->status !== CaseItemStatus::WaitingResponse || $this->submitted_at === null) {
            return null;
        }

        return (int) $this->submitted_at->diffInDays(local_today());
    }

    /** Naziv institucije ili slobodno upisani primalac. */
    public function recipientName(): ?string
    {
        $institution = $this->institution_id !== null ? $this->institution : null;

        return $institution !== null ? $institution->name : ($this->recipient ?: null);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotIn('status', CaseItemStatus::closedValues());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $this->scopeOpen($query)->whereDate('due_date', '<', local_today());
    }

    /** @return BelongsTo<AdministrativeCase, $this> */
    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Stari zapisi istorije (prije centralnog loga aktivnosti).
     *
     * @return HasMany<AdministrativeCaseItemActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AdministrativeCaseItemActivity::class);
    }

    /**
     * Centralni log aktivnosti (project_activities sa ovom stavkom kao subjektom).
     *
     * @return MorphMany<ProjectActivity, $this>
     */
    public function activityLog(): MorphMany
    {
        return $this->morphMany(ProjectActivity::class, 'subject');
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** @return MorphMany<ProjectDocument, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(ProjectDocument::class, 'documentable');
    }

    /** @return MorphMany<Link, $this> */
    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /** Direktan link na detalj stavke. */
    public function url(): string
    {
        return route('tasks.show', $this->id);
    }

    /** @return HasMany<DocumentReference, $this> */
    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
    }

    /**
     * Dokumenti (vanjski linkovi / protokol) dodatno povezani sa stavkom preko pivot tabele.
     *
     * @return BelongsToMany<DocumentReference, $this>
     */
    public function linkedDocumentReferences(): BelongsToMany
    {
        return $this->belongsToMany(DocumentReference::class, 'document_reference_items');
    }
}
