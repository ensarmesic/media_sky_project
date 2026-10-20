<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['administrative_case_id', 'user_id', 'event_type', 'title', 'description', 'metadata'])]
class CaseActivity extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
