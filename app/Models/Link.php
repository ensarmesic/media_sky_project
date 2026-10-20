<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Vanjski link na zadatku ili predmetu. */
#[Fillable(['linkable_type', 'linkable_id', 'user_id', 'title', 'url'])]
class Link extends Model
{
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(['name' => 'Bivši korisnik']);
    }

    public function domain(): string
    {
        return preg_replace('/^www\./', '', (string) parse_url($this->url, PHP_URL_HOST));
    }

    /** Naslov ili, ako nije unesen, domena. */
    public function displayTitle(): string
    {
        return filled($this->title) ? $this->title : $this->domain();
    }
}
