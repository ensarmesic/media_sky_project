<?php

namespace App\Services;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\WorkspaceNotification;

class NotificationDispatchService
{
    /**
     * Distribuira obavještenje svim relevantnim korisnicima poštujući njihove
     * postavke (kanali + isključene vrste obavještenja). Korisnik nikad ne dobija
     * obavijest za vlastitu akciju. Vraća broj poslanih.
     *
     * @param  iterable<User|null>  $users
     * @param  array<string, mixed>  $extra
     */
    public function dispatch(iterable $users, NotificationKind $kind, string $title, string $url, ?string $dueDate = null, array $extra = []): int
    {
        $sent = 0;
        $notified = [];
        foreach ($users as $user) {
            if (! $user instanceof User || isset($notified[$user->id]) || $user->id === auth()->id()) {
                continue;
            }
            $notified[$user->id] = true;
            if ($user->notificationSettingFor()->isKindMuted($kind)) {
                continue;
            }
            $user->notify(new WorkspaceNotification($kind->value, $title, $url, $dueDate, $extra));
            $sent++;
        }

        return $sent;
    }

    /** Adresa iz obavijesti, samo ako vodi unutar aplikacije (zaštita od preusmjeravanja na tuđe stranice). */
    public static function safeUrl(?string $url): string
    {
        $base = rtrim(url('/'), '/');
        $internal = $url !== null && ($url === $base || str_starts_with($url, $base.'/') || (str_starts_with($url, '/') && ! str_starts_with($url, '//')));

        return $internal ? $url : route('notifications.index');
    }
}
