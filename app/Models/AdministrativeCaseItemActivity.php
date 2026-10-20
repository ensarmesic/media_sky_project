<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['administrative_case_item_id', 'user_id', 'event_type', 'title', 'description', 'metadata'])]
class AdministrativeCaseItemActivity extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class, 'administrative_case_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
