<?php

namespace App\Models;

use App\Enums\CaseItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['case_template_id', 'name', 'type', 'progress_weight', 'position', 'is_required_for_closure', 'notes'])]
class CaseTemplateItem extends Model
{
    protected function casts(): array
    {
        return ['type' => CaseItemType::class, 'progress_weight' => 'decimal:2', 'is_required_for_closure' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CaseTemplate::class, 'case_template_id');
    }
}
