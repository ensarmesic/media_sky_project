<?php

namespace App\Services;

use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Komentari na zadacima i predmetima. */
class CommentService
{
    public function create(AdministrativeCaseItem|AdministrativeCase $commentable, User $user, string $body): Comment
    {
        $comment = $commentable->comments()->create(['user_id' => $user->id, 'body' => trim($body)]);
        $this->log($commentable, $user, 'comment.created', 'Dodan komentar', ['comment_id' => $comment->id]);

        return $comment;
    }

    private function log(Model $subject, User $user, string $event, string $title, array $metadata = []): void
    {
        $project = Project::owning($subject);
        if ($project !== null) {
            app(ProjectActivityService::class)->record($project, $event, $title, $user, $subject, null, $metadata);
        }
    }
}
