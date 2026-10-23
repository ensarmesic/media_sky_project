<?php

namespace App\Providers;

use App\Listeners\NotifyAdminsAboutBackup;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        BackupHasFailed::class => [
            NotifyAdminsAboutBackup::class,
        ],
        CleanupHasFailed::class => [
            NotifyAdminsAboutBackup::class,
        ],
        UnhealthyBackupWasFound::class => [
            NotifyAdminsAboutBackup::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
