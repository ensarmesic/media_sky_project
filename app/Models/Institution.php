<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $type
 * @property string|null $address
 * @property string|null $contact_person
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'type', 'address', 'contact_person', 'email', 'phone', 'notes'])]
class Institution extends Model
{
    /** @return HasMany<AdministrativeCase, $this> */
    public function administrativeCases(): HasMany
    {
        return $this->hasMany(AdministrativeCase::class);
    }

    /** @return HasMany<DocumentReference, $this> */
    public function documentReferences(): HasMany
    {
        return $this->hasMany(DocumentReference::class);
    }
}
