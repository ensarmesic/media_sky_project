<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $case_type_id
 * @property int|null $institution_id
 * @property int $is_active
 * @property int $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CaseTemplate extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'case_type_id', 'institution_id', 'is_active', 'created_by'];

    /** @return HasMany<CaseTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CaseTemplateItem::class)->orderBy('position');
    }

    /** @return BelongsTo<CaseType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(CaseType::class, 'case_type_id');
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
