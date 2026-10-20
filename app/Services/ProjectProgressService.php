<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectProgressService
{
    public function setManualProgress(Project $project, User $user, int $progress, string $reason): Project
    {
        if ($progress < 0 || $progress > 100) {
            throw ValidationException::withMessages(['progress' => 'Napredak mora biti između 0 i 100.']);
        }

        return DB::transaction(function () use ($project, $user, $progress, $reason) {
            $previous = $project->progress;
            $project->update(['progress' => $progress]);
            $project->progressChanges()->create([
                'user_id' => $user->id,
                'previous_progress' => $previous,
                'new_progress' => $progress,
                'reason' => $reason,
            ]);
            app(ProjectActivityService::class)->record($project, 'progress_changed', 'Napredak projekta promijenjen', $user, $project, $previous.'% → '.$progress.'% · '.$reason);

            return $project->refresh();
        });
    }
}
