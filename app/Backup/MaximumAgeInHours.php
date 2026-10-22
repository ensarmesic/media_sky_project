<?php

namespace App\Backup;

use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Monitor\HealthCheck;

/** Najnoviji backup ne smije biti stariji od N sati (dnevni backup + rezerva, npr. 26 h). */
class MaximumAgeInHours extends HealthCheck
{
    public function __construct(protected int $hours = 26) {}

    public function checkHealth(BackupDestination $backupDestination): void
    {
        $newest = $backupDestination->backups()->newest();

        $this->failIf($newest === null, 'Nema nijednog backupa.');
        $this->failIf(
            $newest !== null && $newest->date()->lt(now()->subHours($this->hours)),
            'Najnoviji backup je od '.local_time($newest?->date())?->format('d.m.Y. H:i').' (stariji od '.$this->hours.' sati).',
        );
    }
}
