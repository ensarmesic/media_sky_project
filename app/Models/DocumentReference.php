<?php

namespace App\Models;

use App\Enums\DocumentDirection;
use App\Enums\DocumentSyncStatus;
use App\Enums\NotificationKind;
use App\Services\NotificationDispatchService;
use App\Services\ProjectActivityService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'administrative_case_id', 'administrative_case_item_id', 'external_system', 'external_document_id', 'protocol_number', 'title', 'document_type', 'direction', 'url', 'source_app', 'document_date', 'description', 'version', 'replaced_by_id', 'is_archived', 'transferred_at', 'institution_id', 'sync_status', 'last_synced_at'])]
class DocumentReference extends Model
{
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

    protected function casts(): array
    {
        return [
            'direction' => DocumentDirection::class,
            'sync_status' => DocumentSyncStatus::class,
            'document_date' => 'date',
            'transferred_at' => 'date',
            'version' => 'integer',
            'replaced_by_id' => 'integer',
            'is_archived' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    public function administrativeCaseItem(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function previousVersions(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /** Stavke s kojima je dokument dodatno povezan (pored primarne administrative_case_item_id veze). */
    public function linkedItems(): BelongsToMany
    {
        return $this->belongsToMany(AdministrativeCaseItem::class, 'document_reference_items');
    }
}
