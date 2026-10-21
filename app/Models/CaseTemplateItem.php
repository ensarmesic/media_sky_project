<?php

namespace App\Models;

use App\Enums\CaseItemType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $case_template_id
 * @property string $name
 * @property CaseItemType $type
 * @property string $progress_weight
 * @property int $position
 * @property bool $is_required_for_closure
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['case_template_id', 'name', 'type', 'progress_weight', 'position', 'is_required_for_closure', 'notes'])]
class CaseTemplateItem extends Model
{
    protected function casts(): array
    {
        return ['type' => CaseItemType::class, 'progress_weight' => 'decimal:2', 'is_required_for_closure' => 'boolean'];
    }

    /** @return BelongsTo<CaseTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CaseTemplate::class, 'case_template_id');
    }
}
