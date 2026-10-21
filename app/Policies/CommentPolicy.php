<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Comment;
use App\Models\Project;
use App\Models\User;

class CommentPolicy
{
    /** Komentarisati mogu članovi projekta (osim uloge Pregled) i admin. */
    public static function canComment(User $user, ?Project $project): bool
    {
        return $project !== null && $user->role !== UserRole::Viewer && $user->can('view', $project);
    }

    /** Uređivanje: samo autor. */
    public function update(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    /** Brisanje: autor ili admin. */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id || $user->isAdministrator();
    }
}
