<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $administrative_case_item_id
 * @property string $title
 * @property bool $is_done
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['administrative_case_item_id', 'title', 'is_done', 'position'])]
class ChecklistItem extends Model
{
    protected $attributes = ['is_done' => false];

    protected function casts(): array
    {
        return ['is_done' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<AdministrativeCaseItem, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class, 'administrative_case_item_id');
    }
}
