<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A dated copy of the database. START_CRM.cmd only backs up when the app is started,
 * so an installation that stays open for weeks was never backed up in between.
 */
class BackupDatabase extends Command
{
    protected $signature = 'crm:backup {--keep=30 : How many backups to keep} {--path= : Write to this directory instead of database/backups}';

    protected $description = 'Write a dated copy of the database and prune older ones';

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $this->error('Only SQLite is backed up here. For MySQL, schedule a transaction-consistent mysqldump on the server.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: database_path('backups');
        File::ensureDirectoryExists($directory);
        $target = $directory.DIRECTORY_SEPARATOR.'database-'.now()->format('Ymd-His').'.sqlite';

        try {
            // VACUUM INTO writes a consistent snapshot even while the application is serving requests.
            DB::statement('VACUUM INTO ?', [$target]);
        } catch (QueryException $exception) {
            $this->error('The database could not be copied: '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Backed up to '.$target.' ('.number_format(File::size($target) / 1024, 1).' KB)');

        $this->prune($directory, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    private function prune(string $directory, int $keep): void
    {
        $backups = collect(File::glob($directory.DIRECTORY_SEPARATOR.'database-*.sqlite'))
            ->sortByDesc(fn (string $file) => File::lastModified($file))
            ->slice($keep);
        $backups->each(fn (string $file) => File::delete($file));
        if ($backups->isNotEmpty()) {
            $this->line('Removed '.$backups->count().' older backup(s).');
        }
    }
}
