<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'administrative_case_id', 'administrative_case_item_id', 'uploaded_by', 'name', 'path', 'mime_type', 'size', 'description', 'document_type', 'version', 'replaced_by_id', 'is_archived'])]
class ProjectDocument extends Model
{
    protected function casts(): array
    {
        return ['size' => 'integer', 'version' => 'integer', 'replaced_by_id' => 'integer', 'is_archived' => 'boolean'];
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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function previousVersions(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /** Stavke s kojima je datoteka dodatno povezana (pored primarne administrative_case_item_id veze). */
    public function linkedItems(): BelongsToMany
    {
        return $this->belongsToMany(AdministrativeCaseItem::class, 'project_document_items');
    }
}
