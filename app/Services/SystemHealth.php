<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Backup\BackupDestination\BackupDestination;

/**
 * Stanje pozadinskih procesa: scheduler (otkucaj svake minute), queue (zadnji obrađeni job),
 * jobovi na čekanju i neuspjeli jobovi. Otkucaji se čuvaju u kešu, pa nije potrebna nova tabela.
 */
class SystemHealth
{
    public const SCHEDULER_KEY = 'health.scheduler_last_run';

    public const QUEUE_KEY = 'health.queue_last_processed';

    public function recordSchedulerRun(): void
    {
        Cache::forever(self::SCHEDULER_KEY, now()->toIso8601String());
    }

    public function recordJobProcessed(): void
    {
        Cache::forever(self::QUEUE_KEY, now()->toIso8601String());
    }

    public function schedulerLastRun(): ?CarbonImmutable
    {
        return $this->timestamp(self::SCHEDULER_KEY);
    }

    public function queueLastProcessed(): ?CarbonImmutable
    {
        return $this->timestamp(self::QUEUE_KEY);
    }

    public function pendingJobs(): int
    {
        return Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
    }

    public function failedJobs(): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
    }

    /**
     * Upozorenja za admina: scheduler ne radi duže od N minuta ili se jobovi gomilaju.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $staleMinutes = (int) config('projectflow.health.scheduler_stale_minutes', 10);
        $maxPending = (int) config('projectflow.health.max_pending_jobs', 20);
        $warnings = [];

        $lastRun = $this->schedulerLastRun();
        if ($lastRun === null) {
            $warnings[] = 'Scheduler još nije zabilježio nijedno pokretanje (podsjetnici i dnevni poslovi ne rade).';
        } elseif ($lastRun->lt(now()->subMinutes($staleMinutes))) {
            $warnings[] = 'Scheduler nije radio od '.local_time($lastRun)->format('d.m.Y. H:i').' (više od '.$staleMinutes.' min).';
        }

        $pending = $this->pendingJobs();
        if ($pending > $maxPending) {
            $warnings[] = "Na čekanju je {$pending} jobova (granica {$maxPending}) — queue worker vjerovatno ne radi.";
        }

        return $warnings;
    }

    /**
     * Stanje backupa po odredištu (BACKUP_DISKS): zadnji uspješan backup, veličina, broj čuvanih.
     *
     * @return array<int, array{disk: string, reachable: bool, newest: ?CarbonImmutable, size: ?float, count: int, used: float}>
     */
    public function backups(): array
    {
        $name = (string) config('backup.backup.name');

        return collect((array) config('backup.backup.destination.disks'))->map(function (string $disk) use ($name) {
            try {
                $destination = BackupDestination::create($disk, $name);
                $backups = $destination->backups();
                $newest = $backups->newest();

                return [
                    'disk' => $disk, 'reachable' => true,
                    'newest' => $newest !== null ? CarbonImmutable::instance($newest->date()) : null,
                    'size' => $newest?->sizeInBytes(), 'count' => $backups->count(), 'used' => $destination->usedStorage(),
                ];
            } catch (\Throwable) {
                return ['disk' => $disk, 'reachable' => false, 'newest' => null, 'size' => null, 'count' => 0, 'used' => 0.0];
            }
        })->values()->all();
    }

    public static function humanBytes(?float $bytes): string
    {
        if ($bytes === null) {
            return '–';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    }

    private function timestamp(string $key): ?CarbonImmutable
    {
        $value = Cache::get($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
