<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies every row from one database connection to another, for the hop off the
 * local SQLite file onto a hosted server. The target must already be migrated:
 * this moves data, never schema.
 */
class CopyDatabase extends Command
{
    /** Rebuilt by the target itself, or tied to the machine the source ran on. */
    public const SKIP = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private const CHUNK = 500;

    protected $signature = 'crm:db-copy {--from=sqlite} {--to=hosted} {--force : Replace the rows the target already holds}';

    protected $description = 'Copy every table from one database connection to another';

    public function handle(): int
    {
        [$from, $to] = [(string) $this->option('from'), (string) $this->option('to')];
        if ($from === $to) {
            $this->error('The source and the target must be different connections.');

            return self::FAILURE;
        }

        $source = Schema::connection($from);
        $target = Schema::connection($to);

        if (! $target->hasTable('migrations') || DB::connection($to)->table('migrations')->count() === 0) {
            $this->error('Run "php artisan migrate --database='.$to.'" first: this command copies data, not schema.');

            return self::FAILURE;
        }

        $tables = array_values(array_intersect(
            array_diff($this->plain($source->getTableListing()), self::SKIP),
            $this->plain($target->getTableListing()),
        ));

        // Migrations seed roles and permissions, so a freshly migrated target is
        // never empty. Those rows are replaced, not merged: the source's own ids
        // have to land unchanged.
        if ($held = $this->occupied($to, $tables)) {
            if (! $this->option('force')) {
                $this->error('The target already holds rows in: '.implode(', ', $held).'. Pass --force to replace them with the source.');

                return self::FAILURE;
            }
            $this->warn('Replacing the rows the target holds in: '.implode(', ', $held).'.');
        }

        $ordered = $this->order($from, $tables);
        $copied = [];

        DB::connection($to)->transaction(function () use ($from, $to, $ordered, &$copied): void {
            foreach (array_reverse($ordered) as $table) {
                DB::connection($to)->table($table)->delete();
            }
            foreach ($ordered as $table) {
                $copied[$table] = $this->copyTable($from, $to, $table);
            }
        });

        $this->resequence($to, $ordered);

        $this->table(['Table', 'Rows'], collect($copied)->map(fn (int $rows, string $table) => [$table, $rows])->values());
        $this->info('Copied '.array_sum($copied).' rows into '.$to.'.');

        return self::SUCCESS;
    }

    /**
     * Postgres reports a table as public.users where SQLite reports users.
     *
     * @param  array<int, string>  $names
     * @return list<string>
     */
    private function plain(array $names): array
    {
        return array_values(array_map(
            fn (string $name) => str_contains($name, '.') ? substr($name, (int) strrpos($name, '.') + 1) : $name,
            $names,
        ));
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function occupied(string $connection, array $tables): array
    {
        return collect($tables)->filter(fn (string $table) => DB::connection($connection)->table($table)->exists())->values()->all();
    }

    /**
     * Foreign keys decide the insert order. A table that points at itself is left
     * in place and its rows go in by id, so a parent lands before its children.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function order(string $connection, array $tables): array
    {
        $pending = [];
        foreach ($tables as $table) {
            $pending[$table] = collect(Schema::connection($connection)->getForeignKeys($table))
                ->pluck('foreign_table')
                ->map(fn ($foreign) => $this->plain([(string) $foreign])[0])
                ->filter(fn (string $foreign) => $foreign !== $table && in_array($foreign, $tables, true))
                ->unique()->values()->all();
        }

        $ordered = [];
        while ($pending !== []) {
            $free = array_keys(array_filter($pending, fn (array $on) => $on === []));
            if ($free === []) {
                // A cycle between tables: take one and let the insert report it.
                $free = [(string) array_key_first($pending)];
            }
            foreach ($free as $table) {
                $ordered[] = $table;
                unset($pending[$table]);
            }
            $pending = array_map(fn (array $on) => array_values(array_diff($on, $free)), $pending);
        }

        return $ordered;
    }

    private function copyTable(string $from, string $to, string $table): int
    {
        $types = collect(Schema::connection($to)->getColumns($table))
            ->mapWithKeys(fn (array $column) => [$column['name'] => strtolower((string) $column['type_name'])])
            ->all();

        $query = DB::connection($from)->table($table);
        if (array_key_exists('id', $types)) {
            $query->orderBy('id');
        }

        $rows = [];
        $count = 0;
        foreach ($query->cursor() as $record) {
            $rows[] = $this->cast(array_intersect_key((array) $record, $types), $types);
            $count++;
            if (count($rows) === self::CHUNK) {
                DB::connection($to)->table($table)->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::connection($to)->table($table)->insert($rows);
        }

        return $count;
    }

    /**
     * SQLite is loosely typed where Postgres is not, so each value is fitted to
     * the column it is about to land in.
     */
    public static function value(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        if (in_array($type, ['bool', 'boolean'], true)) {
            // SQLite keeps a boolean as 0 or 1, which a real boolean column rejects.
            return (bool) $value;
        }
        if ($value === '' && ! in_array($type, ['char', 'bpchar', 'varchar', 'text', 'json', 'jsonb'], true)) {
            // An unset date or number reads back as an empty string, which a typed column rejects.
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function cast(array $row, array $types): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = self::value($value, $types[$column] ?? '');
        }

        return $row;
    }

    /**
     * Only a counted key has a sequence to put right: a notification is keyed by
     * a uuid, and Postgres has no max(uuid) to set one from.
     *
     * @param  array<string, mixed>|null  $id
     */
    public static function resequences(?array $id): bool
    {
        return in_array(strtolower((string) ($id['type_name'] ?? '')), ['int2', 'int4', 'int8', 'smallint', 'integer', 'bigint'], true);
    }

    /**
     * An explicit id leaves a Postgres sequence behind the rows it just received.
     *
     * @param  list<string>  $tables
     */
    private function resequence(string $connection, array $tables): void
    {
        if (DB::connection($connection)->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($tables as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                continue;
            }
            if (! self::resequences(collect(Schema::connection($connection)->getColumns($table))->firstWhere('name', 'id'))) {
                continue;
            }
            DB::connection($connection)->statement(
                'select setval(s.seq, coalesce((select max(id) from "'.$table.'"), 1)) from (select pg_get_serial_sequence(?, \'id\') as seq) s where s.seq is not null',
                [$table],
            );
        }
    }
}
