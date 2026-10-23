<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;

/**
 * Backup baze i dokumenata (spatie/laravel-backup), isto kao backup:run, uz jednu razliku:
 * kod SQLite baze prvo se pravi konzistentan snimak (VACUUM INTO — samo čita bazu), pa sqlite3 alat nije potreban.
 * Kod MySQL-a se koristi mysqldump. Prilagođena konfiguracija se postavlja samo dok traje backup.
 */
class RunBackup extends Command
{
    protected $signature = 'app:backup';

    protected $description = 'Napravi backup baze i dokumenata na odredišta iz BACKUP_DISKS';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $snapshotDir = storage_path('app/backup-temp/sqlite');
        $original = (array) config('backup');
        $settings = $original;

        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            File::ensureDirectoryExists($snapshotDir);
            $snapshot = $snapshotDir.DIRECTORY_SEPARATOR.'database.sqlite';
            File::delete($snapshot);
            DB::connection($connection)->statement('VACUUM INTO ?', [$snapshot]);

            data_set($settings, 'backup.source.databases', []);
            data_set($settings, 'backup.source.files.include', [...(array) data_get($settings, 'backup.source.files.include', []), $snapshotDir]);
        }

        // spatie/laravel-backup 8 čita postavke iz config('backup...') i tokom samog backupa.
        config(['backup' => $settings]);

        try {
            $this->info('Pravim backup…');
            BackupJobFactory::createFromArray($settings)->disableSignals()->run();
            $this->info('Backup je završen.');

            return self::SUCCESS;
        } catch (Exception $exception) {
            report($exception);
            event($exception instanceof BackupFailed
                ? new BackupHasFailed($exception->getPrevious(), $exception->backupDestination)
                : new BackupHasFailed($exception));
            $this->error('Backup nije uspio: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['backup' => $original]);
            File::deleteDirectory($snapshotDir);
        }
    }
}
