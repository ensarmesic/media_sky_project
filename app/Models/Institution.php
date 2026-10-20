<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'address', 'contact_person', 'email', 'phone', 'notes'])]
class Institution extends Model
{
    use HasFactory;

    public function administrativeCases(): HasMany
    {
        return $this->hasMany(AdministrativeCase::class);
    }

    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
    }
}
