<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->role === UserRole::Viewer
            || $project->responsible_user_id === $user->id
            || $project->created_by === $user->id
            || Project::visibleTo($user)->whereKey($project->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ProjectManager;
    }

    public function update(User $user, Project $project): bool
    {
        return $user->role === UserRole::ProjectManager
            && ($project->responsible_user_id === $user->id || $project->created_by === $user->id);
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
