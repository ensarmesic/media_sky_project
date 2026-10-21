<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Stanje sistema: otkucaj schedulera, queue, upozorenja, kartica za admina i app:health. */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private function health(): SystemHealth
    {
        return app(SystemHealth::class);
    }

    private function addPendingJobs(int $count): void
    {
        foreach (range(1, $count) as $i) {
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        }
    }

    private function addFailedJob(): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Notifications\\WorkspaceNotification', 'uuid' => $uuid, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]),
            'exception' => "RuntimeException: SMTP nije dostupan\n#0 stack", 'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_no_warnings_when_scheduler_is_fresh_and_queue_is_short(): void
    {
        $this->health()->recordSchedulerRun();
        $this->addPendingJobs(20);

        $this->assertSame([], $this->health()->warnings());
    }

    public function test_warns_when_scheduler_never_ran_or_is_stale(): void
    {
        $this->assertStringContainsString('još nije zabilježio', $this->health()->warnings()[0]);

        Cache::forever(SystemHealth::SCHEDULER_KEY, now()->subMinutes(11)->toIso8601String());
        $this->assertStringContainsString('nije radio od', $this->health()->warnings()[0]);

        Cache::forever(SystemHealth::SCHEDULER_KEY, now()->subMinutes(9)->toIso8601String());
        $this->assertSame([], $this->health()->warnings());
    }

    public function test_warns_when_more_than_twenty_jobs_are_pending(): void
    {
        $this->health()->recordSchedulerRun();
        $this->addPendingJobs(21);

        $this->assertStringContainsString('Na čekanju je 21 jobova', $this->health()->warnings()[0]);
    }

    public function test_heartbeat_is_scheduled_every_minute_and_records_run(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'health:heartbeat');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $event->run($this->app);
        $this->assertNotNull($this->health()->schedulerLastRun());
    }

    public function test_processed_job_records_time(): void
    {
        dispatch(fn () => null);

        $this->assertNotNull($this->health()->queueLastProcessed());
    }

    public function test_health_command_exit_code_follows_warnings(): void
    {
        $this->artisan('app:health')->expectsOutputToContain('Scheduler još nije zabilježio')->assertFailed();

        $this->health()->recordSchedulerRun();
        $this->artisan('app:health')->expectsOutputToContain('Sve radi.')->assertSuccessful();
    }

    public function test_system_health_page_is_admin_only_and_manages_failed_jobs(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ProjectManager]))
            ->get(route('system-health.index'))->assertForbidden();

        $first = $this->addFailedJob();
        $second = $this->addFailedJob();
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        Livewire::test('pages::settings.system-health')
            ->assertSee('Neuspjeli jobovi')->assertSee('WorkspaceNotification')->assertSee('SMTP nije dostupan')
            ->call('forget', $first);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $first]);

        Livewire::test('pages::settings.system-health')->call('retry', $second);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $second]);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_admin_sees_header_warning_and_others_do_not(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->get(route('projects.index'))->assertSee('Scheduler još nije zabilježio')->assertSee('Stanje sistema');

        $this->actingAs(User::factory()->create(['role' => UserRole::TeamMember]))
            ->get(route('projects.index'))->assertDontSee('Scheduler još nije zabilježio');
    }
}
