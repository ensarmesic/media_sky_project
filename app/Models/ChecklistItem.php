<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
class ChecklistItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['administrative_case_item_id', 'title', 'is_done', 'position'];

    /** @var array<string, string> */
    protected $casts = ['is_done' => 'boolean', 'position' => 'integer'];

    protected $attributes = ['is_done' => false];

    /** @return BelongsTo<AdministrativeCaseItem, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class, 'administrative_case_item_id');
    }
}
