<?php

namespace App\Services;

use App\Enums\NotificationKind;
use App\Models\AdministrativeCase;
use App\Models\AdministrativeCaseItem;
use App\Models\Comment;
use App\Models\Project;
use App\Models\User;
use App\Policies\CommentPolicy;
use App\Support\RichText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Komentari na stavkama i postupcima (spominjanja, prilozi, uređivanje, brisanje). */
class CommentService
{
    /** @param  array<int, UploadedFile>  $files */
    public function create(AdministrativeCaseItem|AdministrativeCase $commentable, User $user, string $body, array $files = []): Comment
    {
        $project = Project::owning($commentable);
        abort_unless(CommentPolicy::canComment($user, $project), 403);

        return DB::transaction(function () use ($commentable, $user, $body, $files, $project) {
            $comment = $commentable->comments()->create(['user_id' => $user->id, 'body' => trim($body)]);
            foreach ($files as $file) {
                app(DocumentService::class)->store($comment, $user, $file);
            }

            $this->log($project, $commentable, $user, 'comment.created', 'Dodan komentar', $comment);

            $url = $this->url($commentable, $comment);
            $mentioned = $this->mentionedMembers($project, $comment->body);
            app(NotificationDispatchService::class)->dispatch($mentioned, NotificationKind::Mentioned, 'Spomenuti ste u komentaru ('.$user->shortName().'): '.$commentable->name, $url);

            if ($commentable instanceof AdministrativeCaseItem && $commentable->responsible !== null && ! $mentioned->contains('id', $commentable->responsible_user_id)) {
                app(NotificationDispatchService::class)->dispatch([$commentable->responsible], NotificationKind::TaskCommented, 'Novi komentar na vašoj stavci: '.$commentable->name, $url);
            }

            return $comment;
        });
    }

    public function update(Comment $comment, User $user, string $body): Comment
    {
        Gate::authorize('update', $comment);
        $comment->update(['body' => trim($body), 'edited_at' => now()]);
        $this->log(Project::owning($comment), $comment->commentable, $user, 'comment.updated', 'Uređen komentar', $comment);

        return $comment;
    }

    public function delete(Comment $comment, User $user): void
    {
        Gate::authorize('delete', $comment);
        $this->log(Project::owning($comment), $comment->commentable, $user, 'comment.deleted', 'Obrisan komentar', $comment);
        $comment->delete();
    }

    /**
     * Članovi projekta spomenuti kao @korisnicko.ime.
     *
     * @return Collection<int, User>
     */
    public function mentionedMembers(?Project $project, string $body): Collection
    {
        $usernames = RichText::mentions($body);
        if ($project === null || $usernames === []) {
            return collect();
        }

        return $project->members()->whereIn('username', $usernames)->get();
    }

    public function url(AdministrativeCaseItem|AdministrativeCase $commentable, Comment $comment): string
    {
        return $commentable instanceof AdministrativeCaseItem
            ? $commentable->url().'#komentar-'.$comment->id
            : route('projects.show', ['project' => $commentable->project_id, 'postupak' => $commentable->id]);
    }

    private function log(?Project $project, mixed $subject, User $user, string $event, string $title, Comment $comment): void
    {
        if ($project !== null) {
            app(ProjectActivityService::class)->record($project, $event, $title, $user, $subject, mb_strimwidth($comment->body, 0, 200, '…'), ['comment_id' => $comment->id]);
        }
    }
}
