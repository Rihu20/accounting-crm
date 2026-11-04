<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every list and report filters on document type, status and a date, none of which was indexed.
 * Foreign keys already index their own columns, so only the filter columns are added here.
 */
return new class extends Migration
{
    private const INDEXES = [
        'invoices' => [
            'invoices_doc_type_status_index' => ['doc_type', 'status'],
            'invoices_sent_at_index' => ['sent_at'],
            'invoices_invoice_date_index' => ['invoice_date'],
        ],
        'payments' => [
            'payments_reversed_received_on_index' => ['reversed', 'received_on'],
        ],
        'expenses' => [
            'expenses_expense_type_status_index' => ['expense_type', 'status'],
            'expenses_due_date_index' => ['due_date'],
            'expenses_invoice_date_index' => ['invoice_date'],
        ],
        'invoice_notes' => [
            'invoice_notes_note_date_index' => ['note_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
