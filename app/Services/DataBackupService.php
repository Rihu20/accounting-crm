<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * A whole-database backup as one JSON file, independent of the database engine, and its restore.
 * Used to move the data to a new database (for example before a hosted database expires).
 */
class DataBackupService
{
    public const FORMAT = 'analystip-crm-backup';

    /** Working tables that are rebuilt by the application and never worth restoring. */
    public const SKIPPED = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** @return array<int, string> */
    public function tables(): array
    {
        $connection = DB::connection();
        $schema = match ($connection->getDriverName()) {
            'pgsql' => (string) (is_array($path = $connection->getConfig('search_path')) ? ($path[0] ?? 'public') : ($path ?: 'public')),
            'sqlite' => 'main',
            default => $connection->getDatabaseName(),
        };

        return collect(Schema::getTables())
            ->filter(fn (array $table) => ($table['schema'] ?? $schema) === $schema)
            ->pluck('name')->reject(fn ($name) => in_array($name, self::SKIPPED, true) || str_starts_with($name, 'sqlite_'))
            ->sort()->values()->all();
    }

    /** @return array{format: string, version: int, created_at: string, driver: string, tables: array<string, array<int, array<string, mixed>>>} */
    public function export(): array
    {
        $tables = [];
        foreach ($this->tables() as $table) {
            $query = DB::table($table);
            if (Schema::hasColumn($table, 'id')) {
                $query->orderBy('id');
            }
            $tables[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return ['format' => self::FORMAT, 'version' => 1, 'created_at' => now()->toIso8601String(), 'driver' => DB::connection()->getDriverName(), 'tables' => $tables];
    }

    public function exportJson(): string
    {
        return json_encode($this->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Replaces the data of every table in the backup. The schema must already exist (run the migrations first).
     *
     * @param  array<string, mixed>  $backup
     * @return array<string, int> rows restored per table
     */
    public function restore(array $backup): array
    {
        if (($backup['format'] ?? null) !== self::FORMAT || ! is_array($backup['tables'] ?? null)) {
            throw new RuntimeException('This is not an AnalystIP CRM backup file.');
        }
        $known = $this->tables();
        $tables = collect($backup['tables'])->filter(fn ($rows, $table) => in_array($table, $known, true));
        $missing = collect($backup['tables'])->keys()->diff($known)->reject(fn ($t) => in_array($t, self::SKIPPED, true));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('The database has no table '.$missing->implode(', ').'. Run the migrations first.');
        }

        $driver = DB::connection()->getDriverName();
        DB::transaction(function () use ($tables, $driver): void {
            if ($driver === 'pgsql') {
                DB::statement('TRUNCATE TABLE '.$tables->keys()->map(fn ($t) => '"'.$t.'"')->implode(', ').' RESTART IDENTITY CASCADE');
            } else {
                Schema::disableForeignKeyConstraints();
                $tables->keys()->each(fn ($t) => DB::table($t)->delete());
            }
            foreach ($this->insertOrder($tables->keys()->all()) as $table) {
                $rows = $tables[$table];
                $columns = array_flip(Schema::getColumnListing($table));
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table($table)->insert(array_map(fn (array $row) => array_intersect_key($row, $columns), $chunk));
                }
            }
            if ($driver !== 'pgsql') {
                Schema::enableForeignKeyConstraints();
            }
        });

        if ($driver === 'pgsql') {
            foreach ($tables->keys() as $table) {
                $id = collect(Schema::getColumns($table))->firstWhere('name', 'id');
                if ($id && preg_match('/int/i', (string) ($id['type_name'] ?? $id['type'] ?? ''))) {
                    DB::statement("select setval(pg_get_serial_sequence('\"{$table}\"', 'id'), coalesce((select max(id) from \"{$table}\"), 0) + 1, false)");
                }
            }
        }

        return $tables->map(fn ($rows) => count($rows))->all();
    }

    /**
     * Tables ordered so that every table comes after the tables its foreign keys point to.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public function insertOrder(array $tables): array
    {
        $needs = [];
        foreach ($tables as $table) {
            $needs[$table] = collect(Schema::getForeignKeys($table))->pluck('foreign_table')
                ->filter(fn ($other) => $other !== $table && in_array($other, $tables, true))->unique()->values()->all();
        }
        $ordered = [];
        while ($needs) {
            $ready = array_keys(array_filter($needs, fn ($deps) => ! array_diff($deps, $ordered)));
            if (! $ready) {
                // A loop of foreign keys: take the rest as they are.
                $ready = array_keys($needs);
            }
            sort($ready);
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($needs[$table]);
            }
        }

        return $ordered;
    }
}
