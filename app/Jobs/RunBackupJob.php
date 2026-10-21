<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/** "Napravi backup sada" iz Stanja sistema — ide kroz queue da web zahtjev ne čeka. */
class RunBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(): void
    {
        Artisan::call('app:backup');
    }
}
