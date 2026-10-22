<?php

namespace App\Console\Commands;

use App\Services\SystemHealth;
use Illuminate\Console\Command;

/** Stanje schedulera i queue-a za SSH provjeru i monitoring (izlazni kod 1 kad postoji upozorenje). */
class AppHealth extends Command
{
    protected $signature = 'app:health';

    protected $description = 'Prikaži stanje schedulera, queue-a i neuspjelih jobova';

    public function handle(SystemHealth $health): int
    {
        $format = fn (?\DateTimeInterface $time) => local_time($time)?->format('d.m.Y. H:i:s') ?? 'nikad';

        $this->table(['Provjera', 'Stanje'], [
            ['Zadnje pokretanje schedulera', $format($health->schedulerLastRun())],
            ['Zadnji obrađeni job', $format($health->queueLastProcessed())],
            ['Jobova na čekanju', (string) $health->pendingJobs()],
            ['Neuspjelih jobova', (string) $health->failedJobs()],
        ]);

        $warnings = $health->warnings();
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        if ($warnings === []) {
            $this->info('Sve radi.');
        }

        return $warnings === [] ? self::SUCCESS : self::FAILURE;
    }
}
