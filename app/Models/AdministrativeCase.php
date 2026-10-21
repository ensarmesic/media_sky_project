<?php

namespace App\Models;

use App\Enums\CaseItemStatus;
use App\Enums\CaseOutcome;
use App\Enums\CaseProgressStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $description
 * @property int $case_type_id
 * @property int|null $institution_id
 * @property int $responsible_user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $response_due_at
 * @property int $case_status_id
 * @property CaseOutcome|null $outcome
 * @property string|null $notes
 * @property CarbonImmutable|null $closed_at
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $reminder_days_before
 * @property CarbonImmutable|null $deadline_reminded_at
 * @property CarbonImmutable|null $overdue_reminded_at
 * @property float|string $progress_weight
 * @property int $position
 * @property bool $is_archived
 * @property CarbonImmutable|null $archived_at
 * @property string|null $icon
 */
#[Fillable(['project_id', 'name', 'icon', 'description', 'case_type_id', 'institution_id', 'responsible_user_id', 'started_at', 'submitted_at', 'response_due_at', 'reminder_days_before', 'deadline_reminded_at', 'overdue_reminded_at', 'case_status_id', 'outcome', 'notes', 'closed_at', 'progress_weight', 'position', 'is_archived', 'archived_at', 'created_by'])]
class AdministrativeCase extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'date', 'submitted_at' => 'date', 'response_due_at' => 'date', 'reminder_days_before' => 'integer', 'deadline_reminded_at' => 'datetime', 'overdue_reminded_at' => 'datetime', 'closed_at' => 'date', 'outcome' => CaseOutcome::class, 'is_archived' => 'boolean', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<CaseType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id');
    }

    /** @return BelongsTo<CaseStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(CaseStatus::class, 'case_status_id');
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CaseActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CaseActivity::class);
    }

    /** Link na predmet unutar njegovog projekta. */
    public function url(): string
    {
        return route('projects.show', $this->project_id).'#predmet-'.$this->id;
    }

    public function progressPercentage(): int
    {
        $relevantItems = ($this->relationLoaded('items') ? $this->items : $this->items()->get())
            ->where('is_active', true)
            ->reject(fn (AdministrativeCaseItem $item) => $item->status === CaseItemStatus::Cancelled);

        if ($relevantItems->isEmpty()) {
            return 0;
        }

        $totalWeight = $relevantItems->sum(fn (AdministrativeCaseItem $item) => (float) $item->progress_weight);
        if ($totalWeight <= 0) {
            return 0;
        }
        $completedWeight = $relevantItems->where('status', CaseItemStatus::Completed)->sum(fn (AdministrativeCaseItem $item) => (float) $item->progress_weight);

        return (int) round($completedWeight / $totalWeight * 100);
    }

    /** @return HasMany<AdministrativeCaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AdministrativeCaseItem::class)->orderBy('position');
    }

    /** @return HasMany<DocumentReference, $this> */
    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
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

    /**
     * Centralni log aktivnosti (project_activities s ovim postupkom kao subjektom).
     *
     * @return MorphMany<ProjectActivity, $this>
     */
    public function activityLog(): MorphMany
    {
        return $this->morphMany(ProjectActivity::class, 'subject');
    }

    /** Status postupka izračunat iz statusa aktivnih stavki (koristi učitane stavke ako postoje). */
    public function progressStatus(): CaseProgressStatus
    {
        $items = ($this->relationLoaded('items') ? $this->items : $this->items()->get())->where('is_active', true);

        return CaseProgressStatus::fromItemStatuses($items->pluck('status'));
    }

    /** @return array{done: int, total: int, percent: int} Završene / sve stavke (bez otkazanih). */
    public function itemCounts(): array
    {
        $items = ($this->relationLoaded('items') ? $this->items : $this->items()->get())
            ->where('is_active', true)
            ->reject(fn (AdministrativeCaseItem $item) => $item->status === CaseItemStatus::Cancelled);
        $done = $items->where('status', CaseItemStatus::Completed)->count();

        return ['done' => $done, 'total' => $items->count(), 'percent' => $items->isEmpty() ? 0 : (int) round($done / $items->count() * 100)];
    }

    /** @return MorphMany<Link, $this> */
    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable');
    }
}
