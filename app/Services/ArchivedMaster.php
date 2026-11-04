<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Masters are never deleted through the application, but all four use soft deletes, so a row
 * archived directly in the database stays behind its unique index while Eloquent hides it.
 * Reusing that name or reference then failed with a raw constraint violation; these checks
 * turn it into a message that says which record is in the way.
 */
final class ArchivedMaster
{
    /** Unique columns an archived row can still be holding, and what to call them. */
    public const KEYS = [
        Client::class => ['name' => 'name', 'ref_no' => 'Client Ref No'],
        Vendor::class => ['name' => 'name'],
        Matter::class => ['our_ref' => 'matter reference'],
        Paralegal::class => ['email' => 'email address'],
    ];

    public static function guard(Model $record): void
    {
        foreach (self::KEYS[$record::class] ?? [] as $column => $label) {
            if (blank($record->{$column})) {
                continue;
            }
            $archived = self::holding($record::class, $column, (string) $record->{$column})
                ->when($record->exists, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                ->first();
            if ($archived) {
                throw ValidationException::withMessages([$column => self::message($record::class, $column, $label, $archived->getKey())]);
            }
        }
    }

    /** @param  class-string<Model>  $model */
    public static function holding(string $model, string $column, string $value): Builder
    {
        return $model::onlyTrashed()->where($column, $value);
    }

    /** @param  class-string<Model>  $model */
    public static function message(string $model, string $column, string $label, int|string $key): string
    {
        return 'An archived '.self::label($model).' (#'.$key.') still uses this '.$label
            .'. Restore it with "php artisan crm:archived-masters --restore='.class_basename($model).':'.$key.'" or use a different value.';
    }

    /** @param  class-string<Model>  $model */
    public static function label(string $model): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($model))));
    }
}
