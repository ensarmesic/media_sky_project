<?php

namespace App\Models;

use App\Enums\CaseItemStatus;
use App\Enums\CaseOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['project_id', 'name', 'description', 'case_type_id', 'institution_id', 'responsible_user_id', 'started_at', 'submitted_at', 'response_due_at', 'reminder_days_before', 'deadline_reminded_at', 'overdue_reminded_at', 'case_status_id', 'outcome', 'notes', 'closed_at', 'progress_weight', 'position', 'is_archived', 'archived_at', 'created_by'])]
class AdministrativeCase extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['started_at' => 'date', 'submitted_at' => 'date', 'response_due_at' => 'date', 'reminder_days_before' => 'integer', 'deadline_reminded_at' => 'datetime', 'overdue_reminded_at' => 'datetime', 'closed_at' => 'date', 'outcome' => CaseOutcome::class, 'is_archived' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CaseStatus::class, 'case_status_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

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

    public function items(): HasMany
    {
        return $this->hasMany(AdministrativeCaseItem::class)->orderBy('position');
    }

    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
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
}
