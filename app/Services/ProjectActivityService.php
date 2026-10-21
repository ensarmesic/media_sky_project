<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectActivityService
{
    /** @param  array<string, mixed>  $metadata */
    public function record(Project $project, string $eventType, string $title, ?User $user = null, ?Model $subject = null, ?string $description = null, array $metadata = []): ProjectActivity
    {
        return $project->activities()->create([
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }
}
