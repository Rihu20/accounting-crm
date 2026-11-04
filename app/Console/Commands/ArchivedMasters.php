<?php

namespace App\Console\Commands;

use App\Services\ArchivedMaster;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Masters cannot be deleted in the app, so an archived client, vendor, matter or paralegal
 * can only come from a change made straight in the database. This lists them and puts one back.
 */
class ArchivedMasters extends Command
{
    protected $signature = 'crm:archived-masters {--restore= : Put one record back, for example Client:12}';

    protected $description = 'List archived master records, or restore one';

    public function handle(): int
    {
        return $this->option('restore') ? $this->restore((string) $this->option('restore')) : $this->list();
    }

    private function list(): int
    {
        $rows = [];
        foreach (array_keys(ArchivedMaster::KEYS) as $model) {
            foreach ($model::onlyTrashed()->get() as $record) {
                $rows[] = [class_basename($model), $record->getKey(), $this->describe($record), $record->deleted_at?->format('d.m.Y H:i')];
            }
        }
        if (! $rows) {
            $this->info('No archived master records.');

            return self::SUCCESS;
        }
        $this->table(['Type', 'Id', 'Record', 'Archived on'], $rows);
        $this->line('Restore one with --restore=Type:Id');

        return self::SUCCESS;
    }

    private function restore(string $target): int
    {
        [$type, $key] = array_pad(explode(':', $target, 2), 2, null);
        $model = collect(array_keys(ArchivedMaster::KEYS))->first(fn (string $class) => strcasecmp(class_basename($class), (string) $type) === 0);
        if (! $model || ! is_numeric($key)) {
            $this->error('Use --restore=Type:Id, where Type is one of: '.collect(array_keys(ArchivedMaster::KEYS))->map(fn ($class) => class_basename($class))->implode(', '));

            return self::FAILURE;
        }
        $record = $model::onlyTrashed()->find($key);
        if (! $record) {
            $this->error('No archived '.ArchivedMaster::label($model).' with id '.$key.'.');

            return self::FAILURE;
        }
        $record->restore();
        $this->info('Restored '.ArchivedMaster::label($model).' #'.$key.' ('.$this->describe($record).').');

        return self::SUCCESS;
    }

    private function describe(Model $record): string
    {
        return (string) ($record->name ?? $record->our_ref ?? $record->email ?? '—');
    }
}
