<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AdministrativeCase;
use App\Models\User;

class AdministrativeCasePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AdministrativeCase $case): bool
    {
        return $user->can('view', $case->project);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ProjectManager;
    }

    public function update(User $user, AdministrativeCase $case): bool
    {
        return $user->role === UserRole::ProjectManager && $user->can('update', $case->project);
    }
}
