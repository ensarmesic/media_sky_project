<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Komentar na zadatku ili predmetu. */
#[Fillable(['commentable_type', 'commentable_id', 'user_id', 'body', 'edited_at'])]
class Comment extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(['name' => 'Bivši korisnik']);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(ProjectDocument::class, 'documentable');
    }

    /** Projekat kojem komentar pripada (preko zadatka ili predmeta). */
    public function project(): ?Project
    {
        return Project::owning($this->commentable);
    }
}
