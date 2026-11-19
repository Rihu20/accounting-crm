<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $settings = [
        'company_pan' => '',
        'a2_purpose' => 'Official and Professional Payment for Patent Filing, Technical Translation and Prosecution Matters',
        'a2_purpose_code' => 'S1004',
        'a2_purpose_desc' => 'Legal Services',
        'a2_charges' => 'OUR',
        'a2_source_funds' => 'Business Income',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->text('address')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('bank_address')->nullable();
            $table->string('swift')->nullable();
            $table->string('corr_bank_name')->nullable();
            $table->text('corr_bank_address')->nullable();
            $table->string('corr_bank_swift')->nullable();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->date('a2_prepared_on')->nullable();
        });
        foreach ($this->settings as $key => $value) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys($this->settings))->delete();
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('a2_prepared_on'));
        Schema::table('vendors', fn (Blueprint $table) => $table->dropColumn(['address', 'bank_account', 'bank_name', 'bank_address', 'swift', 'corr_bank_name', 'corr_bank_address', 'corr_bank_swift']));
    }
};
