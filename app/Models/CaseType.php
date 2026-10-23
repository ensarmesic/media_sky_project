<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $system_key
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CaseType extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'system_key', 'is_active'];

    /** @var array<string, string> */
    protected $casts = ['is_active' => 'boolean'];

    /** @return HasMany<AdministrativeCase, $this> */
    public function administrativeCases(): HasMany
    {
        return $this->hasMany(AdministrativeCase::class);
    }
}
