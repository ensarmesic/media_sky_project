<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $category_id
 * @property string|null $location
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $planned_end_date
 * @property int $responsible_user_id
 * @property ProjectStatus $status
 * @property int $progress
 * @property string|null $notes
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property string|null $next_step
 * @property string|null $completion_note
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $archived_at
 * @property string|null $current_state
 */
#[Fillable(['code', 'name', 'description', 'category_id', 'location', 'start_date', 'planned_end_date', 'responsible_user_id', 'status', 'progress', 'notes', 'next_step', 'current_state', 'completion_note', 'completed_at', 'archived_at', 'created_by'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    public const ROLE_MANAGER = 'manager';

    public const ROLE_MEMBER = 'member';

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

    /**
     * Korisnik vidi projekte kojih je član (ili odgovorna osoba / autor); admin vidi sve.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdministrator()) {
            return $query;
        }

        return $query->where(fn (Builder $scope) => $scope
            ->where('responsible_user_id', $user->id)
            ->orWhere('created_by', $user->id)
            ->orWhereHas('members', fn (Builder $members) => $members->whereKey($user->id)));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNot('status', ProjectStatus::Archived->value);
    }

    /** Dodaje korisnika kao člana projekta ako to već nije (npr. kad mu se dodijeli stavka). */
    public function ensureMember(?User $user, string $role = self::ROLE_MEMBER): bool
    {
        if ($user === null || $this->members()->whereKey($user->id)->exists()) {
            return false;
        }
        $this->members()->attach($user->id, ['role' => $role]);

        return true;
    }

    /** Projekat kojem pripada zapis (projekat, postupak, stavka, komentar, dokument, link). */
    public static function owning(?Model $model): ?self
    {
        return match (true) {
            $model instanceof self => $model,
            $model instanceof AdministrativeCaseItem => $model->administrativeCase?->project,
            $model instanceof AdministrativeCase => $model->project,
            $model instanceof Comment => self::owning($model->commentable),
            $model instanceof ProjectDocument => $model->project,
            $model instanceof Link => self::owning($model->linkable),
            default => null,
        };
    }

    /** @return BelongsTo<ProjectCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'category_id');
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

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<ProjectProgressChange, $this> */
    public function progressChanges(): HasMany
    {
        return $this->hasMany(ProjectProgressChange::class);
    }

    /** @return HasMany<AdministrativeCase, $this> */
    public function administrativeCases(): HasMany
    {
        return $this->hasMany(AdministrativeCase::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Sve stavke projekta (kroz postupke).
     *
     * @return HasManyThrough<AdministrativeCaseItem, AdministrativeCase, $this>
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(AdministrativeCaseItem::class, AdministrativeCase::class);
    }

    /**
     * Administrativni napredak u %: svaki (nearhivirani) postupak nosi jednak dio, a unutar postupka
     * se gleda udio završenih stavki. Npr. 3 od 4 završena postupka, a četvrti bez ijedne završene stavke = 75 %.
     * Null ako projekat još nema postupaka. Koristi učitane postupke (i njihove brojače) ako postoje.
     */
    public function administrativeProgress(): ?int
    {
        $cases = ($this->relationLoaded('administrativeCases') ? $this->administrativeCases : $this->administrativeCases()->with('items')->get())
            ->where('is_archived', false);

        if ($cases->isEmpty()) {
            return null;
        }

        return (int) round($cases->avg(fn (AdministrativeCase $case) => $case->completionRatio()) * 100);
    }

    /** Zadržano zbog postojećih poziva (PDF izvještaj). */
    public function administrativeProgressPercentage(): ?int
    {
        return $this->administrativeProgress();
    }

    /**
     * Svi dokumenti projekta (projekat, postupci, stavke, komentari).
     *
     * @return HasMany<ProjectDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    /**
     * Dokumenti dodani direktno na projekat.
     *
     * @return MorphMany<ProjectDocument, $this>
     */
    public function ownDocuments(): MorphMany
    {
        return $this->morphMany(ProjectDocument::class, 'documentable');
    }

    /** @return HasMany<DocumentReference, $this> */
    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
    }

    /** @return MorphMany<Link, $this> */
    public function links(): MorphMany
    {
        return $this->morphMany(Link::class, 'linkable');
    }

    /** @return HasMany<ProjectActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }
}
