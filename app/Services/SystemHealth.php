<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $warnings[] = 'Scheduler nije radio od '.$lastRun->format('d.m.Y. H:i').' (više od '.$staleMinutes.' min).';
        }

        $pending = $this->pendingJobs();
        if ($pending > $maxPending) {
            $warnings[] = "Na čekanju je {$pending} jobova (granica {$maxPending}) — queue worker vjerovatno ne radi.";
        }

        return $warnings;
    }

    private function timestamp(string $key): ?CarbonImmutable
    {
        $value = Cache::get($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
