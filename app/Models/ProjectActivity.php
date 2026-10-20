<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['project_id', 'user_id', 'event_type', 'subject_type', 'subject_id', 'title', 'description', 'metadata'])]
class ProjectActivity extends Model
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Heroicon prema vrsti događaja (za liste aktivnosti). */
    public function icon(): string
    {
        $event = $this->event_type;

        return match (true) {
            str_contains($event, 'created') => 'plus',
            str_contains($event, 'submitted') || ($this->metadata['field'] ?? null) === 'submitted_at' => 'arrow-right',
            str_contains($event, 'response') || ($this->metadata['field'] ?? null) === 'response_at' => 'envelope',
            str_starts_with($event, 'document') => 'paper-clip',
            str_starts_with($event, 'comment') => 'chat-bubble-left',
            str_starts_with($event, 'link') => 'link',
            str_contains($event, 'status') => 'pencil-square',
            str_contains($event, 'deleted') || str_contains($event, 'removed') => 'trash',
            str_starts_with($event, 'project') || str_contains($event, 'progress') || str_contains($event, 'next_step') => 'flag',
            default => 'clock',
        };
    }
}
