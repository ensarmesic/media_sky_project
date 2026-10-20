<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'name', 'description', 'category_id', 'location', 'start_date', 'planned_end_date', 'responsible_user_id', 'status', 'progress', 'notes', 'next_step', 'completion_note', 'completed_at', 'archived_at', 'created_by'])]
class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'start_date' => 'date',
            'planned_end_date' => 'date',
            'progress' => 'integer',
            'completed_at' => 'date',
            'archived_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator() || $user->role === UserRole::Viewer) {
            return $query;
        }

        return $query->where(fn (Builder $scope) => $scope
            ->where('responsible_user_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->id))
            // Osoba kojoj je dodijeljen predmet ili zadatak vidi i projekat u kojem se nalazi.
            ->orWhereHas('administrativeCases', fn (Builder $cases) => $cases
                ->where('responsible_user_id', $user->id)
                ->orWhereHas('items', fn (Builder $items) => $items->where('responsible_user_id', $user->id)->where('is_active', true))));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNot('status', ProjectStatus::Archived->value);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'category_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function progressChanges(): HasMany
    {
        return $this->hasMany(ProjectProgressChange::class);
    }

    public function administrativeCases(): HasMany
    {
        return $this->hasMany(AdministrativeCase::class)->orderBy('position')->orderBy('id');
    }

    public function administrativeProgressPercentage(): ?float
    {
        $cases = ($this->relationLoaded('administrativeCases') ? $this->administrativeCases : $this->administrativeCases()->with('items')->get())
            ->filter(fn (AdministrativeCase $case) => ! $case->is_archived && $case->items->where('is_active', true)->isNotEmpty());
        if ($cases->isEmpty()) {
            return null;
        }
        $totalWeight = $cases->sum(fn (AdministrativeCase $case) => (float) $case->progress_weight);
        if ($totalWeight <= 0) {
            return null;
        }

        return round($cases->sum(fn (AdministrativeCase $case) => $case->progressPercentage() * (float) $case->progress_weight) / $totalWeight, 1);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }
}
