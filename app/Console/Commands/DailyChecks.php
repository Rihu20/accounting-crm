<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\NotifyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DailyChecks extends Command
{
    protected $signature = 'crm:daily-checks';

    protected $description = 'Send idempotent payment, expense and reimbursement reminders';

    public function handle(NotifyService $notify): int
    {
        foreach (Invoice::whereIn('status', ['Sent', 'Partially received'])->whereNotNull('sent_at')->get() as $i) {
            $days = (int) $i->sent_at->copy()->startOfDay()->diffInDays(today());
            $first = max(1, (int) Setting::get('followup_days', 15));
            $repeat = max(1, (int) Setting::get('followup_repeat_days', 7));
            if ($days >= $first && ($days - $first) % $repeat === 0) {
                $this->once('followup:'.$i->id.':'.today()->toDateString(), function () use ($notify, $i) {
                    $notify->paralegals($i, 'Payment follow-up due', 'Please follow up on unpaid proforma '.$i->number);
                    $notify->admins('Unpaid proforma', $i->number.' requires follow-up.');
                });
            }
        }
        foreach (Expense::where('expense_type', 'vendor')->where('status', 'Unpaid')->whereDate('due_date', '<=', today()->addDays(3))->get() as $e) {
            $this->once('vendor:'.$e->id.':'.today()->toDateString(), fn () => $notify->admins('Vendor invoice due', 'Vendor invoice '.$e->vendor_invoice_no.' is due '.$e->due_date?->format('d.m.Y')));
        }
        if (today()->dayOfWeek === 1) {
            $count = Expense::where('expense_type', 'owner')->where('reimbursement_status', 'Pending')->whereDate('invoice_date', '<=', today()->subDays(30))->count();
            if ($count) {
                $this->once('reimbursement:'.today()->format('o-W'), fn () => $notify->admins('Owner reimbursements pending', $count.' owner expenses have been pending for 30+ days.'));
            }
        }
        $this->info('Daily checks complete.');

        return self::SUCCESS;
    }

    private function once(string $key, callable $action): void
    {
        DB::transaction(function () use ($key, $action) {
            $created = DB::table('reminder_events')->insertOrIgnore(['event_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            if ($created) {
                $action();
            }
        });
    }
}
