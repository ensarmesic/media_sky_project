<?php

namespace App\Models;

use App\Enums\NotificationKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'email_enabled', 'database_enabled', 'daily_digest', 'digest_hour', 'muted_kinds'])]
class UserNotificationSetting extends Model
{
    /**
     * Podrazumijevane vrijednosti moraju postojati i na modelu, jer firstOrCreate()
     * ne učitava DB default vrijednosti — bez ovoga prvo obavještenje nema kanala.
     */
    protected $attributes = [
        'email_enabled' => true,
        'database_enabled' => true,
        'daily_digest' => false,
        'digest_hour' => 7,
    ];

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'database_enabled' => 'boolean', 'daily_digest' => 'boolean', 'digest_hour' => 'integer', 'muted_kinds' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isKindMuted(NotificationKind $kind): bool
    {
        return in_array($kind->value, $this->muted_kinds ?? [], true);
    }

    public function channels(): array
    {
        $channels = [];
        if ($this->database_enabled) {
            $channels[] = 'database';
        }
        if ($this->email_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
