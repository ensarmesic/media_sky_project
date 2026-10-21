<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $administrative_case_item_id
 * @property int|null $user_id
 * @property string $event_type
 * @property string $title
 * @property string|null $description
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['administrative_case_item_id', 'user_id', 'event_type', 'title', 'description', 'metadata'])]
class AdministrativeCaseItemActivity extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<AdministrativeCaseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AdministrativeCaseItem::class, 'administrative_case_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
