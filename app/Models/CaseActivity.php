<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $administrative_case_id
 * @property int $user_id
 * @property string $event_type
 * @property string $title
 * @property string|null $description
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['administrative_case_id', 'user_id', 'event_type', 'title', 'description', 'metadata'])]
class CaseActivity extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<AdministrativeCase, $this> */
    public function administrativeCase(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
