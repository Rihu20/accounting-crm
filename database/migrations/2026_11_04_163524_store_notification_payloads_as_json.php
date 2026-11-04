<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Laravel keeps a notification's payload in a text column. SQLite and MySQL read
 * JSON straight out of text, but Postgres has no ->> operator for it, so the
 * unread count Filament puts in the topbar brought down every panel page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table notifications alter column data type json using data::json');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table notifications alter column data type text using data::text');
        }
    }
};
