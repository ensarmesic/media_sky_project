<?php

namespace App\Services;

use App\Enums\NotificationKind;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Support\Collection;

class NotificationDispatchService
{
    /**
     * Distribuira obavještenje svim relevantnim korisnicima poštujući njihove
     * postavke (kanali + isključene vrste obavještenja). Korisnik nikad ne dobija
     * obavijest za vlastitu akciju. Vraća broj poslanih.
     */
    public function dispatch(iterable|Collection $users, NotificationKind $kind, string $title, string $url, ?string $dueDate = null, array $extra = []): int
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
}
