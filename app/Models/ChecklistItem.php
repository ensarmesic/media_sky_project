<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stavka checkliste (podzadatak) unutar zadatka. */
#[Fillable(['administrative_case_item_id', 'title', 'is_done', 'position'])]
class ChecklistItem extends Model
{
    protected $attributes = ['is_done' => false];

    protected function casts(): array
    {
        return ['is_done' => 'boolean', 'position' => 'integer'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class, 'administrative_case_item_id');
    }
}
