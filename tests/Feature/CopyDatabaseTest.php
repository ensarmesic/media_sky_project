<?php

namespace Tests\Feature;

use App\Models\ProjectActivity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** app:copy-database: prenos svih podataka (npr. SQLite → MySQL) uz provjeru broja redova. */
class CopyDatabaseTest extends TestCase
{
    private string $sourcePath;

    private string $targetPath;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['source', 'target'] as $name) {
            $path = tempnam(sys_get_temp_dir(), "msp-{$name}-").'.sqlite';
            touch($path);
            $this->{$name.'Path'} = $path;
            config(["database.connections.copy_{$name}" => [...config('database.connections.sqlite'), 'database' => $path]]);
            Artisan::call('migrate', ['--database' => "copy_{$name}", '--force' => true]);
        }

        $source = DB::connection('copy_source');
        $userId = $source->table('users')->insertGetId(['name' => 'Ensar Mešić', 'username' => 'ensar', 'password' => 'x', 'role' => 'administrator', 'created_at' => now(), 'updated_at' => now()]);
        $categoryId = $source->table('project_categories')->insertGetId(['name' => 'Telekomunikacije', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $projectId = $source->table('projects')->insertGetId([
            'code' => 'FTTH-1', 'name' => 'FTTH Banovići', 'category_id' => $categoryId, 'start_date' => '2026-04-24', 'status' => 'in_progress',
            // SQLite dozvoljava prazan tekst u datumu; u cilju (MySQL) to mora biti NULL.
            'planned_end_date' => '', 'description' => '',
            'responsible_user_id' => $userId, 'created_by' => $userId, 'progress' => 40, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $source->table('project_user')->insert(['project_id' => $projectId, 'user_id' => $userId, 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $source->table('project_activities')->insert(['project_id' => $projectId, 'user_id' => $userId, 'event_type' => 'project.progress', 'title' => 'Promijenjena ručna procjena', 'metadata' => json_encode(['field' => 'progress', 'old' => 0, 'new' => 40]), 'created_at' => now(), 'updated_at' => now()]);
        $source->table('sessions')->insert(['id' => 'abc', 'payload' => 'x', 'last_activity' => time()]);
    }

    protected function tearDown(): void
    {
        DB::purge('copy_source');
        DB::purge('copy_target');
        foreach ([$this->sourcePath, $this->targetPath] as $path) {
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_copies_all_tables_with_ids_and_json_and_skips_sessions(): void
    {
        $this->artisan('app:copy-database', ['from' => 'copy_source', 'to' => 'copy_target', '--sqlite-path' => $this->sourcePath])
            ->expectsOutputToContain('Prenos završen')
            ->assertSuccessful();

        $target = DB::connection('copy_target');
        $this->assertSame(1, $target->table('projects')->where('code', 'FTTH-1')->count());
        $this->assertNull($target->table('projects')->value('planned_end_date'));
        $this->assertSame('', $target->table('projects')->value('description'));
        $this->assertSame(1, $target->table('project_user')->count());
        $this->assertSame(0, $target->table('sessions')->count());

        $activity = (new ProjectActivity)->setConnection('copy_target')->newQuery()->firstOrFail();
        $this->assertSame(40, $activity->metadata['new']);
        $this->assertSame(DB::connection('copy_source')->table('projects')->value('id'), $target->table('projects')->value('id'));
    }

    public function test_refuses_to_overwrite_target_without_force(): void
    {
        $this->artisan('app:copy-database', ['from' => 'copy_source', 'to' => 'copy_target', '--sqlite-path' => $this->sourcePath])->assertSuccessful();

        $this->artisan('app:copy-database', ['from' => 'copy_source', 'to' => 'copy_target', '--sqlite-path' => $this->sourcePath])
            ->expectsOutputToContain('već imaju podatke')
            ->assertFailed();

        $this->artisan('app:copy-database', ['from' => 'copy_source', 'to' => 'copy_target', '--sqlite-path' => $this->sourcePath, '--force' => true])
            ->assertSuccessful();
        $this->assertSame(1, DB::connection('copy_target')->table('projects')->count());
    }

    public function test_requires_migrated_target(): void
    {
        $empty = tempnam(sys_get_temp_dir(), 'msp-empty-').'.sqlite';
        touch($empty);
        config(['database.connections.copy_empty' => [...config('database.connections.sqlite'), 'database' => $empty]]);

        $this->artisan('app:copy-database', ['from' => 'copy_source', 'to' => 'copy_empty', '--sqlite-path' => $this->sourcePath])
            ->expectsOutputToContain('nije migrirana')
            ->assertFailed();

        DB::purge('copy_empty');
        @unlink($empty);
    }
}
