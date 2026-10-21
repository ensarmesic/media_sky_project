<?php

namespace Tests\Feature;

use App\Enums\NotificationKind;
use App\Enums\UserRole;
use App\Jobs\RunBackupJob;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Backup\Events\BackupHasFailed;
use Tests\TestCase;
use ZipArchive;

/** Backup (spatie/laravel-backup): sadržaj, raspored, nadzor starosti i obavijesti adminu. */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'msp-backup-'.uniqid();
        File::ensureDirectoryExists($this->root.'/docs/projekti/1');
        File::put($this->root.'/docs/projekti/1/dopis.pdf', '%PDF-1.4 test');

        config([
            'filesystems.disks.backups' => ['driver' => 'local', 'root' => $this->root.'/backups', 'throw' => true],
            'backup.backup.name' => 'media-sky-project',
            'backup.backup.source.files.include' => [$this->root.'/docs'],
            'backup.backup.source.files.exclude' => [],
            'backup.backup.destination.disks' => ['backups'],
            'backup.backup.temporary_directory' => $this->root.'/tmp',
            'backup.monitor_backups.0.disks' => ['backups'],
            'backup.monitor_backups.0.name' => 'media-sky-project',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_backup_contains_database_snapshot_and_documents(): void
    {
        // Izvor je zaseban SQLite fajl (RefreshDatabase drži testnu bazu u transakciji, a VACUUM to ne dozvoljava).
        touch($path = $this->root.'/izvor.sqlite');
        config(['database.connections.backup_source' => [...config('database.connections.sqlite'), 'database' => $path], 'database.default' => 'backup_source']);
        $this->artisan('migrate', ['--database' => 'backup_source', '--force' => true]);
        DB::connection('backup_source')->table('users')->insert(['name' => 'Ensar', 'username' => 'ensar', 'password' => 'x', 'role' => 'administrator']);

        $this->artisan('app:backup')->assertSuccessful();

        $zips = File::glob($this->root.'/backups/media-sky-project/*.zip');
        $this->assertCount(1, $zips);
        $zip = new ZipArchive;
        $zip->open($zips[0]);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => str_replace('\\', '/', (string) $zip->getNameIndex($i)));
        $zip->close();

        $this->assertTrue($names->contains(fn (string $name) => str_ends_with($name, 'docs/projekti/1/dopis.pdf')), $names->implode(', '));
        $this->assertTrue($names->contains(fn (string $name) => str_ends_with($name, 'sqlite/database.sqlite')), $names->implode(', '));
        $this->assertDirectoryDoesNotExist(storage_path('app/backup-temp/sqlite'));
        DB::purge('backup_source');
        config(['database.default' => 'sqlite']);
    }

    public function test_backup_commands_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event) => [trim(str_replace(['"', "'"], '', strstr((string) $event->command, 'artisan') ?: '')) => $event->expression]);

        $this->assertSame('0 2 * * *', $events['artisan app:backup']);
        $this->assertSame('0 3 * * *', $events['artisan backup:clean']);
        $this->assertSame('0 4 * * *', $events['artisan backup:monitor']);
    }

    public function test_admins_are_notified_when_newest_backup_is_older_than_26_hours(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $member = User::factory()->create(['role' => UserRole::TeamMember]);
        File::ensureDirectoryExists($this->root.'/backups/media-sky-project');
        File::put($this->root.'/backups/media-sky-project/'.now()->subHours(30)->format('Y-m-d-H-i-s').'.zip', 'zip');
        Notification::fake();

        $this->artisan('backup:monitor');

        Notification::assertSentTo($admin, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->kind === NotificationKind::BackupProblem->value && str_contains($n->title, 'stariji od 26 sati'));
        Notification::assertNotSentTo($member, WorkspaceNotification::class);
    }

    public function test_fresh_backup_is_healthy_and_missing_backup_is_reported(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        Notification::fake();

        $this->artisan('backup:monitor');
        Notification::assertSentTo($admin, WorkspaceNotification::class, fn (WorkspaceNotification $n) => str_contains($n->title, 'Nema nijednog backupa'));

        Notification::fake();
        File::ensureDirectoryExists($this->root.'/backups/media-sky-project');
        File::put($this->root.'/backups/media-sky-project/'.now()->subHours(2)->format('Y-m-d-H-i-s').'.zip', 'zip');
        $this->artisan('backup:monitor');
        Notification::assertNothingSent();
    }

    public function test_failed_backup_notifies_admins(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        Notification::fake();

        event(new BackupHasFailed(new \RuntimeException('Disk je pun')));

        Notification::assertSentTo($admin, WorkspaceNotification::class, fn (WorkspaceNotification $n) => $n->title === 'Backup nije uspio: Disk je pun');
    }

    public function test_backup_now_button_queues_job_for_admin_only(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        Livewire::test('pages::settings.system-health')
            ->assertSee('Napravi backup sada')
            ->call('backupNow');

        Queue::assertPushed(RunBackupJob::class);
    }
}
