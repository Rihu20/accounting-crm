<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractVendorInvoice;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExtractionController extends Controller
{
    public function upload(Request $r)
    {
        abort_unless($r->user()->is_active && $r->user()->allowed('accounting.expenses.edit_all'), 403);
        $this->requireConnection();
        $r->validate(['file' => 'required|file|mimes:pdf|max:15360']);
        $path = $r->file('file')->store('Expenses/'.now()->format('Y-m'), config('crm.disk'));
        $id = DB::table('expense_extractions')->insertGetId(['user_id' => auth()->id(), 'path' => $path, 'status' => 'Queued', 'created_at' => now(), 'updated_at' => now()]);
        $this->read($id);

        return redirect('/admin/vendor-scan')->with('success', 'PDF received. Review the reading result below.');
    }

    public function retry(Request $request, int $id)
    {
        abort_unless($request->user()->is_active && $request->user()->allowed('accounting.expenses.edit_all'), 403);
        $this->requireConnection();

        DB::transaction(function () use ($request, $id): void {
            $extraction = DB::table('expense_extractions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($extraction && $extraction->user_id === $request->user()->id, 404);
            abort_unless(in_array($extraction->status, ['Manual review', 'Review required']), 422, 'This upload is already processing or has been confirmed.');
            DB::table('expense_extractions')->where('id', $id)->update(['status' => 'Queued', 'error' => null, 'updated_at' => now()]);

        });

        $this->read($id);

        return redirect('/admin/vendor-scan')->with('success', 'Your saved PDF has been read. Review the result below.');
    }

    private function read(int $id): void
    {
        if (config('crm.reader_mode') === 'local') {
            set_time_limit(150);
            ExtractVendorInvoice::dispatchSync($id);
        } else {
            ExtractVendorInvoice::dispatch($id);
        }
    }

    private function requireConnection(): void
    {
        if (config('crm.reader_mode') === 'local') {
            return;
        }
        if (! filled(config('crm.anthropic_key')) || ! filled(config('crm.anthropic_model'))) {
            throw ValidationException::withMessages(['file' => 'AI reader is not connected yet. Configure the Anthropic API key and model first, or use manual expense entry.']);
        }
    }

    public function confirm(Request $r, int $id)
    {
        abort_unless($r->user()->allowed('accounting.expenses.edit_all'), 403);
        $data = $r->validate(['expense_group' => 'nullable|in:foreign,patent_office,indian', 'patent_office_id' => 'nullable|required_if:expense_group,patent_office|exists:patent_offices,id', 'vendor_id' => 'nullable|required_without:vendor_name|exists:vendors,id', 'vendor_name' => 'nullable|required_without:vendor_id|string|max:255', 'vendor_country' => 'nullable|required_without:vendor_id|string|max:255', 'vendor_invoice_no' => 'required|max:255', 'invoice_date' => 'required|date', 'due_date' => 'nullable|date', 'currency' => 'required|in:'.implode(',', config('crm.expense_currencies')), 'amount' => 'required|numeric|min:0.01', 'gst_amount' => 'nullable|numeric|min:0', 'ref_no' => 'nullable|regex:/^\d{4}$/|exists:clients,ref_no', 'notes' => 'nullable|max:4000']);
        DB::transaction(function () use ($id, $data) {
            $e = DB::table('expense_extractions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($e && $e->user_id === auth()->id() && in_array($e->status, ['Review required', 'Manual review']), 422);
            if (empty($data['vendor_id'])) {
                $vendor = Vendor::firstOrCreate(['name' => $data['vendor_name']], ['country' => $data['vendor_country'], 'vendor_type' => strcasecmp($data['vendor_country'], 'India') === 0 ? 'Indian' : 'Foreign', 'default_currency' => $data['currency']]);
                $data['vendor_id'] = $vendor->id;
            }
            unset($data['vendor_name'], $data['vendor_country']);
            $data['gst_amount'] = $data['gst_amount'] ?? 0;
            Expense::create($data + ['expense_type' => 'vendor', 'paid_by' => 'company_bank', 'paid_by_user_id' => auth()->id(), 'category' => 'Other', 'status' => 'Unpaid', 'bill_path' => $e->path, 'extraction_json' => $e->data ? json_decode($e->data, true) : null]);
            DB::table('expense_extractions')->where('id', $id)->update(['status' => 'Confirmed', 'updated_at' => now()]);
        });

        return redirect('/admin/expenses')->with('success', 'Reviewed vendor expense saved.');
    }
}
