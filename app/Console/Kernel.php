<?php

namespace App\Console;

use App\Services\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Raspored radi po lokalnom satu (config app.schedule_timezone, preuzima ga scheduleTimezone()).
     */
    protected function schedule(Schedule $schedule): void
    {
        // Otkucaj schedulera za "Stanje sistema" i app:health.
        $schedule->call(fn () => app(SystemHealth::class)->recordSchedulerRun())->everyMinute()->name('health:heartbeat');

        $schedule->command('items:send-deadline-reminders')->dailyAt('07:00')->withoutOverlapping();
        $schedule->command('cases:send-deadline-reminders')->dailyAt('07:05')->withoutOverlapping();
        $schedule->command('items:send-waiting-reminders')->dailyAt('07:10')->withoutOverlapping();
        // Backup (spatie/laravel-backup): backup u 02:00, čišćenje starih u 03:00, provjera starosti (26 h) u 04:00.
        $schedule->command('app:backup')->dailyAt('02:00')->withoutOverlapping();
        $schedule->command('backup:clean')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('backup:monitor')->dailyAt('04:00');

        // Svaki korisnik bira sat dnevnog sažetka (digest_hour), pa se komanda pokreće svaki sat.
        $schedule->command('notifications:send-daily-digest')->hourly()->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
