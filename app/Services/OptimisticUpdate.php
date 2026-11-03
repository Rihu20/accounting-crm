<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OptimisticUpdate
{
    public function save(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $current = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            if ($current instanceof Invoice && $current->status !== 'Draft') {
                throw ValidationException::withMessages(['lock_version' => 'This invoice has been finalised. Reopen the invoice list to see its current status.']);
            }
            if (! isset($data['lock_version']) || (int) $current->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Someone else updated this record. Close this form and reopen it to see their changes.']);
            }
            unset($data['lock_version']);
            $current->fill($data);
            $current->lock_version++;
            $current->save();
            $record->setRawAttributes($current->getAttributes(), true);

            return $record;
        });
    }
}
