<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'case_type_id', 'institution_id', 'is_active', 'created_by'])]
class CaseTemplate extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(CaseTemplateItem::class)->orderBy('position');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
