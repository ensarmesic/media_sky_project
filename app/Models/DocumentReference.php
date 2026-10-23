<?php

namespace App\Models;

use App\Enums\DocumentDirection;
use App\Enums\DocumentSyncStatus;
use App\Enums\NotificationKind;
use App\Services\NotificationDispatchService;
use App\Services\ProjectActivityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $administrative_case_id
 * @property string $external_system
 * @property string $external_document_id
 * @property string|null $protocol_number
 * @property string $title
 * @property string|null $document_type
 * @property DocumentDirection $direction
 * @property CarbonImmutable|null $document_date
 * @property CarbonImmutable|null $transferred_at
 * @property int|null $institution_id
 * @property DocumentSyncStatus $sync_status
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $administrative_case_item_id
 * @property string|null $url
 * @property string|null $source_app
 * @property string|null $description
 * @property int $version
 * @property int|null $replaced_by_id
 * @property bool $is_archived
 */
class DocumentReference extends Model
{
    /** @var list<string> */
    protected $fillable = ['project_id', 'administrative_case_id', 'administrative_case_item_id', 'external_system', 'external_document_id', 'protocol_number', 'title', 'document_type', 'direction', 'url', 'source_app', 'document_date', 'description', 'version', 'replaced_by_id', 'is_archived', 'transferred_at', 'institution_id', 'sync_status', 'last_synced_at'];

    /** @var array<string, string> */
    protected $casts = [
        'direction' => DocumentDirection::class,
        'sync_status' => DocumentSyncStatus::class,
        'document_date' => 'date',
        'transferred_at' => 'date',
        'version' => 'integer',
        'replaced_by_id' => 'integer',
        'is_archived' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (DocumentReference $reference): void {
            $reference->loadMissing('project.responsible');
            app(ProjectActivityService::class)->record(
                $reference->project,
                'document_linked',
                'Evidentiran dokument: '.$reference->title,
                auth()->user(),
                $reference,
                $reference->protocol_number ? 'Broj protokola: '.$reference->protocol_number : null,
            );

            app(NotificationDispatchService::class)->dispatch(
                [$reference->project->responsible],
                NotificationKind::DocumentLinked,
                'Novi dokument na projektu: '.$reference->title,
                route('projects.show', $reference->project),
                $reference->document_date?->format('Y-m-d'),
            );
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<AdministrativeCase, $this> */
    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    /** @return BelongsTo<AdministrativeCaseItem, $this> */
    public function administrativeCaseItem(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class);
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<self, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /** @return HasMany<self, $this> */
    public function previousVersions(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /**
     * Stavke s kojima je dokument dodatno povezan (pored primarne administrative_case_item_id veze).
     *
     * @return BelongsToMany<AdministrativeCaseItem, $this>
     */
    public function linkedItems(): BelongsToMany
    {
        return $this->belongsToMany(AdministrativeCaseItem::class, 'document_reference_items');
    }
}
