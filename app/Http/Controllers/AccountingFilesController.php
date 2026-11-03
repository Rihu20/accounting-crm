<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\InvoiceDocument;
use App\Services\AccountingFileService;
use App\Services\PortalWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AccountingFilesController extends Controller
{
    public function uploadInvoices(Request $r, AccountingFileService $service)
    {
        abort_unless($r->user()->allowed('accounting.invoices.create'), 403);
        $r->validate(['files' => 'required|array|min:1|max:20', 'files.*' => 'required|file|mimes:pdf|max:25600']);
        $paths = [];
        $names = [];
        foreach ($r->file('files') as $file) {
            $path = $file->store('InvoiceUploads/'.$r->user()->id, config('crm.disk'));
            $paths[] = $path;
            $names[$path] = $file->getClientOriginalName();
        }
        $service->archive($paths, $r->only(['doc_type', 'client_id', 'invoice_date', 'period_year', 'period_month', 'notes']) + ['file_names' => $names], $r->user());

        return redirect('/admin/invoice-documents')->with('success', 'Invoice PDFs uploaded.');
    }

    public function invoice(InvoiceDocument $document)
    {
        abort_unless(\App\Filament\Resources\InvoiceDocumentResource::canViewAny(), 403);
        abort_unless(preg_match('#^InvoiceUploads/[A-Za-z0-9/_-]+\.pdf$#i', $document->path), 404);

        abort_unless(Storage::disk(config('crm.disk'))->exists($document->path), 404, 'The PDF file is missing. Upload the original again.');

        return Storage::disk(config('crm.disk'))->response($document->path, $document->file_name, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function expense(Expense $expense)
    {
        $u = auth()->user();
        abort_unless($u->allowed('accounting.expenses.view_all') || ($u->allowed('accounting.expenses.view_own') && $expense->paid_by_user_id === $u->id) || ($u->allowed('accounting.expenses.view_paralegal') && PortalWorkflow::paralegalExpenses(Expense::query())->whereKey($expense)->exists()), 403);
        abort_unless($expense->bill_path && preg_match('#^Expenses/[A-Za-z0-9/_-]+\.(pdf|png|jpe?g)$#i', $expense->bill_path), 404);

        abort_unless(Storage::disk(config('crm.disk'))->exists($expense->bill_path), 404, 'The receipt file is missing.');

        return Storage::disk(config('crm.disk'))->response($expense->bill_path, basename($expense->bill_path), ['X-Content-Type-Options' => 'nosniff']);
    }
}
