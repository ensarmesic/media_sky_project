<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $linkable_type
 * @property int $linkable_id
 * @property int $user_id
 * @property string|null $title
 * @property string $url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Link extends Model
{
    /** @var list<string> */
    protected $fillable = ['linkable_type', 'linkable_id', 'user_id', 'title', 'url'];

    /** @return MorphTo<Model, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
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
