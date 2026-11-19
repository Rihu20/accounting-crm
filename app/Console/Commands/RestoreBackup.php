<?php

namespace App\Console\Commands;

use App\Services\DataBackupService;
use Illuminate\Console\Command;
use Throwable;

/** Loads a JSON backup made with "Download full backup" into this database, replacing its data. */
class RestoreBackup extends Command
{
    protected $signature = 'crm:restore-backup {file : The .json backup file} {--force : Replace the data without asking}';

    protected $description = 'Replace the data of this database with a JSON backup (run the migrations first)';

    public function handle(DataBackupService $backups): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error('No file at '.$file);

            return self::FAILURE;
        }
        try {
            $backup = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('The file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Backup made on '.($backup['created_at'] ?? 'unknown date').' from '.($backup['driver'] ?? 'unknown').'.');
        if (! $this->option('force') && ! $this->confirm('Every table in the backup will be emptied and refilled in this database. Continue?')) {
            return self::FAILURE;
        }
        try {
            $counts = $backups->restore($backup);
        } catch (Throwable $e) {
            $this->error('Nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Table', 'Rows'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info('Restore complete: '.array_sum($counts).' rows.');

        return self::SUCCESS;
    }
}
