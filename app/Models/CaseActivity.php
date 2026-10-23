<?php

namespace App\Models;

use App\Models\Concerns\LimitsTitleLength;
use Carbon\CarbonImmutable;
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
class CaseActivity extends Model
{
    use LimitsTitleLength;

    /** @var list<string> */
    protected $fillable = ['administrative_case_id', 'user_id', 'event_type', 'title', 'description', 'metadata'];

    /** @var array<string, string> */
    protected $casts = ['metadata' => 'array'];

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
