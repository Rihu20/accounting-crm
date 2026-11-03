<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('matters')->whereNotNull('our_ref')->where('our_ref', '!=', '')->groupBy('our_ref')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate matter dockets must be reviewed before adding the unique reference index.');
        }
        Schema::table('clients', fn (Blueprint $t) => $t->string('ref_no', 4)->nullable()->unique());
        DB::table('matters')->where('our_ref', '')->update(['our_ref' => null]);
        Schema::table('matters', function (Blueprint $t) {
            $t->dropUnique(['ref_no']);
            $t->index('ref_no');
            $t->unique('our_ref');
        });
        foreach (DB::table('matters')->select('client_id')->distinct()->pluck('client_id') as $clientId) {
            $refs = DB::table('matters')->where('client_id', $clientId)->distinct()->pluck('ref_no');
            if ($refs->count() === 1 && preg_match('/^\d{4}$/', $refs[0])) {
                DB::table('clients')->where('id', $clientId)->update(['ref_no' => $refs[0]]);
            }
        }
        Schema::table('storage_folders', function (Blueprint $t) {
            $t->foreignId('matter_id')->nullable()->constrained()->nullOnDelete();
            $t->dropUnique(['paralegal_id', 'ref_no', 'folder_date']);
            $t->unique(['matter_id', 'folder_date']);
        });
        foreach (DB::table('storage_folders')->get() as $folder) {
            $matches = DB::table('matters')->where('ref_no', $folder->ref_no)->where('paralegal_id', $folder->paralegal_id)->pluck('id');
            if ($matches->count() === 1) {
                DB::table('storage_folders')->where('id', $folder->id)->update(['matter_id' => $matches[0]]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('matters')->groupBy('ref_no')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple matters now share a client reference; this migration cannot be rolled back safely.');
        }
        Schema::table('storage_folders', function (Blueprint $t) {
            $t->dropUnique(['matter_id', 'folder_date']);
            $t->dropConstrainedForeignId('matter_id');
            $t->unique(['paralegal_id', 'ref_no', 'folder_date']);
        });
        Schema::table('matters', function (Blueprint $t) {
            $t->dropUnique(['our_ref']);
            $t->dropIndex(['ref_no']);
            $t->unique('ref_no');
        });
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('ref_no'));
    }
};
