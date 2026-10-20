<?php

namespace App\Policies;

use App\Models\ProjectCategory;
use App\Models\User;

class ProjectCategoryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ProjectCategory $category): bool
    {
        return false;
    }
}
