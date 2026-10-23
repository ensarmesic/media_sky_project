<?php

namespace App\Models;

use App\Models\Concerns\LimitsTitleLength;
use Carbon\CarbonImmutable;
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
class AdministrativeCaseItemActivity extends Model
{
    use LimitsTitleLength;

    /** @var list<string> */
    protected $fillable = ['administrative_case_item_id', 'user_id', 'event_type', 'title', 'description', 'metadata'];

    /** @var array<string, string> */
    protected $casts = ['metadata' => 'array'];

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
