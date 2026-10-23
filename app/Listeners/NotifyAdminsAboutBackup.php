<?php

namespace App\Listeners;

use App\Enums\NotificationKind;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\NotificationDispatchService;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

/** Problem s backupom → obavijest svim adminima (zvono + e-mail prema njihovim postavkama). */
class NotifyAdminsAboutBackup
{
    public function handle(BackupHasFailed|CleanupHasFailed|UnhealthyBackupWasFound $event): void
    {
        $title = match (true) {
            $event instanceof BackupHasFailed => 'Backup nije uspio: '.$event->exception->getMessage(),
            $event instanceof CleanupHasFailed => 'Čišćenje starih backupa nije uspjelo: '.$event->exception->getMessage(),
            default => 'Problem s backupom ('.$event->backupDestinationStatus->backupDestination()->diskName().'): '
                .$event->backupDestinationStatus->getHealthCheckFailure()?->exception()->getMessage(),
        };

        app(NotificationDispatchService::class)->dispatch(
            User::where('role', UserRole::Administrator->value)->get(),
            NotificationKind::BackupProblem,
            mb_strimwidth($title, 0, 250, '…'),
            route('system-health.index'),
        );
    }
}
