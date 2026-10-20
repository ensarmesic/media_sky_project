<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'system_key', 'is_active'])]
class CaseType extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function administrativeCases(): HasMany
    {
        return $this->hasMany(AdministrativeCase::class);
    }
}
