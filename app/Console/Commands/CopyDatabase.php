<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prenos podataka između konekcija (npr. lokalni SQLite → produkcijski MySQL).
 * Ciljna baza mora biti migrirana (php artisan migrate --database=mysql) i prazna.
 */
class CopyDatabase extends Command
{
    protected $signature = 'app:copy-database
        {from=sqlite : Izvorna konekcija iz config/database.php}
        {to=mysql : Ciljna konekcija (mora biti migrirana)}
        {--sqlite-path= : Putanja do SQLite fajla izvora (podrazumijevano database/database.sqlite)}
        {--force : Isprazni tabele u cilju ako već imaju podatke}';

    protected $description = 'Prenesi sve podatke iz jedne baze u drugu (npr. SQLite → MySQL) i provjeri broj redova';

    /** Tabele koje se ne prenose: migracije postoje u cilju, keš i sesije se mogu izgubiti bez štete. */
    private const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'sqlite_sequence'];

    private const CHUNK = 500;

    public function handle(): int
    {
        $from = $this->text($this->argument('from'), 'sqlite');
        $to = $this->text($this->argument('to'), 'mysql');
        if ($from === $to) {
            $this->error('Izvor i cilj moraju biti različite konekcije.');

            return self::FAILURE;
        }
        if (config("database.connections.{$from}.driver") === 'sqlite') {
            $path = $this->text($this->option('sqlite-path'), database_path('database.sqlite'));
            if (! is_file($path)) {
                $this->error("SQLite fajl ne postoji: {$path}");

                return self::FAILURE;
            }
            config(["database.connections.{$from}.database" => $path]);
            DB::purge($from);
        }

        $source = DB::connection($from);
        $target = DB::connection($to);
        if (! Schema::connection($to)->hasTable('migrations')) {
            $this->error("Ciljna baza nije migrirana. Prvo: php artisan migrate --database={$to}");

            return self::FAILURE;
        }

        $tables = collect(Schema::connection($from)->getTableListing())
            ->reject(fn (string $table) => in_array($table, self::SKIP, true))
            ->values();
        $targetTables = collect(Schema::connection($to)->getTableListing());

        $missing = $tables->diff($targetTables);
        if ($missing->isNotEmpty()) {
            $this->warn('Preskačem tabele kojih nema u cilju: '.$missing->implode(', '));
        }
        $tables = $tables->intersect($targetTables)->values();

        $filled = $tables->filter(fn (string $table) => $target->table($table)->exists());
        if ($filled->isNotEmpty() && ! $this->option('force')) {
            $this->error('Ciljne tabele već imaju podatke: '.$filled->implode(', ').'. Pokreni s --force da se isprazne.');

            return self::FAILURE;
        }

        Schema::connection($to)->disableForeignKeyConstraints();
        try {
            $rows = [];
            foreach ($tables as $table) {
                if ($this->option('force')) {
                    $target->table($table)->delete();
                }
                $rows[] = [$table, $this->copyTable($source, $target, $from, $to, $table), $target->table($table)->count()];
            }
        } finally {
            Schema::connection($to)->enableForeignKeyConstraints();
        }

        $this->table(['Tabela', 'Izvor', 'Cilj'], $rows);
        $mismatch = collect($rows)->filter(fn (array $row) => $row[1] !== $row[2]);
        if ($mismatch->isNotEmpty()) {
            $this->error('Broj redova se ne poklapa za: '.$mismatch->pluck(0)->implode(', '));

            return self::FAILURE;
        }
        $this->info('Prenos završen: '.count($rows).' tabela, '.collect($rows)->sum(fn (array $row) => $row[1]).' redova.');

        return self::SUCCESS;
    }

    /** Vrijednost argumenta/opcije kao tekst (prazno → podrazumijevano). */
    private function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function copyTable(ConnectionInterface $source, ConnectionInterface $target, string $from, string $to, string $table): int
    {
        $columns = array_values(array_intersect(
            Schema::connection($from)->getColumnListing($table),
            Schema::connection($to)->getColumnListing($table),
        ));
        // SQLite dozvoljava '' u kolonama za datum/broj, MySQL ne: takve vrijednosti idu kao NULL ("nije uneseno").
        $strict = collect(Schema::connection($to)->getColumns($table))
            ->filter(fn (mixed $column): bool => is_array($column) && preg_match('/date|time|year|int|decimal|numeric|float|double|real/i', (string) ($column['type_name'] ?? '')) === 1)
            ->pluck('name')->flip()->all();
        $copied = 0;
        $batch = [];
        foreach ($source->table($table)->select($columns)->cursor() as $row) {
            $row = (array) $row;
            foreach ($row as $column => $value) {
                if ($value === '' && isset($strict[$column])) {
                    $row[$column] = null;
                }
            }
            $batch[] = $row;
            if (count($batch) >= self::CHUNK) {
                $target->table($table)->insert($batch);
                $copied += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $target->table($table)->insert($batch);
            $copied += count($batch);
        }

        return $copied;
    }
}
