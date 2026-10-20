<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AdministrativeCaseItem;
use App\Models\User;

class AdministrativeCaseItemPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    public function view(User $user, AdministrativeCaseItem $item): bool
    {
        return $item->responsible_user_id === $user->id || $user->can('view', $item->administrativeCase);
    }

    /**
     * Zadatak može ažurirati onaj ko upravlja predmetom ili osoba kojoj je zadatak dodijeljen
     * (status, datumi, napomene) — pregledači nikada.
     */
    public function update(User $user, AdministrativeCaseItem $item): bool
    {
        if ($user->role === UserRole::Viewer) {
            return false;
        }

        return $item->responsible_user_id === $user->id
            || $user->can('update', $item->administrativeCase);
    }
}
