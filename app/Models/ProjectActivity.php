<?php

namespace App\Models;

use App\Models\Concerns\LimitsTitleLength;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property-read User|null $user
 * @property-read Model|null $subject
 * @property int $id
 * @property int $project_id
 * @property int|null $user_id
 * @property string $event_type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $title
 * @property string|null $description
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProjectActivity extends Model
{
    use LimitsTitleLength;

    /** @var list<string> */
    protected $fillable = ['project_id', 'user_id', 'event_type', 'subject_type', 'subject_id', 'title', 'description', 'metadata'];

    /** @var array<string, string> */
    protected $casts = ['metadata' => 'array'];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
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
