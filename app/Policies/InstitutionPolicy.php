<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Institution;
use App\Models\User;

class InstitutionPolicy
{
    /** Pregled institucije vide svi prijavljeni; stavke su ionako ograničene na projekte korisnika. */
    public function view(User $user, Institution $institution): bool
    {
        return true;
    }

    /** Kontakt podatke uređuje voditelj projekta ili admin. */
    public function update(User $user, Institution $institution): bool
    {
        return $user->isAdministrator() || $user->role === UserRole::ProjectManager;
    }
}
