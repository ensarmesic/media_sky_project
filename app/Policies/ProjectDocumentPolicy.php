<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ProjectDocument;
use App\Models\User;

class ProjectDocumentPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    /** Dokument vidi svako ko vidi projekat kojem dokument pripada. */
    public function view(User $user, ProjectDocument $document): bool
    {
        return $document->project !== null && $user->can('view', $document->project);
    }

    /** Brisanje: autor dokumenta ili voditelj projekta (admin kroz before()). */
    public function delete(User $user, ProjectDocument $document): bool
    {
        if ($user->role === UserRole::Viewer || ! $this->view($user, $document)) {
            return false;
        }

        return $document->uploaded_by === $user->id || $user->can('update', $document->project);
    }
}
