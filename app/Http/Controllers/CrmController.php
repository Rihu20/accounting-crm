<?php

namespace App\Http\Controllers;

use App\Exports\RowsExport;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Services\NoteService;
use App\Models\StorageFolder;
use App\Models\WordUpload;
use App\Services\InvoiceService;
use App\Services\MasterImportService;
use App\Services\NotifyService;
use App\Services\PdfService;
use App\Services\WordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Exception;

class CrmController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()->is_active && auth()->user()->allowed('accounting.masters.import'), 403);
    }

    private function portal(): void
    {
        abort_unless(auth()->user()->is_active && (auth()->user()->allowed('accounting.documents.manage') || auth()->user()->allowed('accounting.invoices.create')), 403);
    }

    public function importPreview(Request $r, MasterImportService $service)
    {
        $this->admin();
        $r->session()->forget('master_preview');
        $r->validate(['file' => 'required|file|mimes:xlsx|max:10240']);
        $path = $r->file('file')->store('Imports');
        try {
            $preview = $service->preview(Storage::path($path));
        } catch (Exception $exception) {
            throw ValidationException::withMessages(['file' => 'This workbook could not be read. Save it as a valid .xlsx file and upload it again.']);
        }
        $r->session()->put('master_preview', $preview);

        return redirect('/admin/import-masters');
    }

    public function importCommit(Request $r, MasterImportService $s)
    {
        $this->admin();
        abort_unless($r->session()->has('master_preview'), 422);
        $s->commit($r->session()->get('master_preview'));
        $r->session()->forget('master_preview');

        return redirect('/admin/import-masters')->with('success', 'Master records imported successfully.');
    }

    public function importErrors()
    {
        $this->admin();

        return response()->streamDownload(function () {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Sheet', 'Row', 'Error']);
            foreach (session('master_preview.errors', []) as $e) {
                fputcsv($f, array_values($e));
            }fclose($f);
        }, 'import-errors.csv', ['Content-Type' => 'text/csv']);
    }

    public function masterTemplate()
    {
        $this->admin();

        return response()->download(base_path('docs/CRM_Master_Data.xlsx'));
    }

    public function wordTemplate(Request $r, WordService $s)
    {
        $this->portal();
        $path = tempnam(sys_get_temp_dir(), 'crm').'.docx';
        $data = $r->validate(['format' => 'nullable|in:combined,detailed,india_gst']);
        $layout = $data['format'] ?? 'detailed';
        $s->template($path, $layout);

        $names = ['combined' => 'Paralegal_Fees_Combined.docx', 'detailed' => 'Paralegal_Fees_Detailed.docx', 'india_gst' => 'Paralegal_Fees_India_GST.docx'];

        return response()->download($path, $r->has('format') ? $names[$layout] : 'Paralegal_Fee_Template.docx')->deleteFileAfterSend();
    }

    public function wordPreview(Request $r, WordService $s)
    {
        $this->portal();
        $r->session()->forget('word_preview');
        $r->validate(['file' => 'required|file|mimes:docx|max:10240', 'invoice_layout' => 'nullable|in:auto,combined,detailed,india_gst', 'client_ref' => ['nullable', 'regex:/^\d{4}$/']]);
        $path = $r->file('file')->store('Uploads/Word/'.now()->format('Y-m'), config('crm.disk'));
        $stream = Storage::disk(config('crm.disk'))->get($path);
        $tmp = tempnam(sys_get_temp_dir(), 'word');
        file_put_contents($tmp, $stream);
        try {
            $choice = $r->input('invoice_layout');
            try {
                $result = $s->parse($tmp, $r->user(), $choice === 'auto' ? null : $choice, $r->input('client_ref'));
            } catch (\PhpOffice\PhpWord\Exception\Exception $exception) {
                throw ValidationException::withMessages(['file' => 'This Word document could not be read. Save it as a valid .docx file and upload it again.']);
            }
        } finally {
            unlink($tmp);
        }$u = WordUpload::create(['file_path' => $path, 'uploaded_by' => auth()->id(), 'paralegal_id' => $result['paralegal_id'], 'client_id' => $result['client_id'], 'status' => $result['errors'] ? 'Rejected' : 'Validated', 'parse_result' => $result]);
        $r->session()->put('word_preview', $result + ['upload_id' => $u->id]);

        return redirect($r->user()->allowed('accounting.invoices.create') ? '/admin/word-upload' : '/portal/word-upload');
    }

    public function wordCreate(Request $r, InvoiceService $s)
    {
        $this->portal();
        $p = $r->session()->get('word_preview');
        abort_unless($p && ! $p['errors'], 422, 'Validate a Word file first');
        $invoice = DB::transaction(function () use ($p, $s) {
            $u = WordUpload::lockForUpdate()->findOrFail($p['upload_id']);
            abort_unless($u->uploaded_by === auth()->id() && $u->status === 'Validated', 403);
            $approved = $u->parse_result;
            abort_unless($approved && empty($approved['errors']), 422, 'Validate the Word file again.');
            app(WordService::class)->createMissingMatters($approved, auth()->user());
            $i = $s->draft(auth()->user(), (int) $approved['client_id'], $approved['currency'], $approved['rows'], $u->id, $approved['invoice_layout'] ?? null);
            $u->update(['status' => 'Created']);

            return $i;
        });
        $r->session()->forget('word_preview');
        app(NotifyService::class)->admins('Draft proforma ready', 'Word upload created draft #'.$invoice->id);

        return redirect('/admin/invoices')->with('success', 'Draft proforma created. Review and finalise it.');
    }

    public function pdf(Invoice $invoice, PdfService $s)
    {
        $u = auth()->user();
        abort_unless($u->is_active && $u->allowed('accounting.invoices.view'), 403);

        return response($s->render($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($invoice->number ?? 'Draft').'.pdf"']);
    }

    public function notePdf(InvoiceNote $note, NoteService $s)
    {
        abort_unless(auth()->user()->allowed('accounting.notes.view'), 403);

        return response($s->render($note), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$note->number.'.pdf"']);
    }

    public function gstr1(Request $r, \App\Services\ReportService $reports)
    {
        abort_unless(auth()->user()->allowed('accounting.reports.view'), 403);
        $month = $r->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']])['month'];

        return Excel::download(new \App\Exports\SheetsExport($reports->gstr1($month)), 'GSTR1_Working_'.$month.'.xlsx');
    }

    public function ageing(\App\Services\ReportService $reports)
    {
        abort_unless(auth()->user()->allowed('accounting.reports.view'), 403);

        return Excel::download(new RowsExport($reports->ageingRows()), 'Receivables_Ageing_'.today()->format('Y-m-d').'.xlsx');
    }

    public function documentUpload(Request $r)
    {
        $this->portal();
        $r->validate(['folder_id' => 'required|exists:storage_folders,id', 'file' => 'required|file|mimes:pdf,docx,xlsx,jpg,jpeg,png,zip|max:25600']);
        $folder = StorageFolder::findOrFail($r->folder_id);
        abort_unless(auth()->user()->allowed('accounting.documents.manage') || (auth()->user()->hasRole('Paralegal') && auth()->user()->paralegal?->id && $folder->paralegal_id === auth()->user()->paralegal->id), 403);
        $path = $r->file('file')->store($folder->storage_path, config('crm.disk'));
        Document::create(['storage_folder_id' => $folder->id, 'matter_id' => $folder->matter_id, 'type' => 'work', 'name' => $r->file('file')->getClientOriginalName(), 'path' => $path, 'uploaded_by' => auth()->id()]);

        return back()->with('success', 'Document uploaded.');
    }

    public function download(Document $document)
    {
        $this->portal();
        $folder = StorageFolder::find($document->storage_folder_id);
        abort_unless(auth()->user()->allowed('accounting.documents.manage') || (auth()->user()->hasRole('Paralegal') && auth()->user()->paralegal?->id && $folder?->paralegal_id === auth()->user()->paralegal->id), 403);

        abort_unless(Storage::disk(config('crm.disk'))->exists($document->path), 404, 'The document file is missing.');

        return Storage::disk(config('crm.disk'))->download($document->path, $document->name);
    }

    public function export()
    {
        abort_unless(auth()->user()->is_active && auth()->user()->allowed('accounting.invoices.export'), 403);
        $rows = [['Invoice No', 'Type', 'Client Ref Nos', 'Matter Ref Nos', 'Client', 'Paralegal', 'Currency', 'Total', 'Received', 'TDS', 'Written off', 'Outstanding', 'Invoice date', 'Status']];
        foreach (Invoice::with(['client', 'paralegal', 'items'])->get() as $i) {
            $sum = $i->receivedAmount();
            $rows[] = [$i->number, $i->doc_type, $i->items->pluck('ref_no')->unique()->implode(', '), $i->items->pluck('our_ref')->filter()->unique()->implode(', '), $i->client->name, $i->paralegal?->name, $i->currency, $i->grand_total, $sum, $i->registerPaymentTotal('tds_amount'), $i->registerPaymentTotal('write_off_amount'), $i->outstandingAmount(), $i->invoice_date->format('d.m.Y'), $i->status];
        }

        return Excel::download(new RowsExport($rows), 'Payment_Tracker.xlsx');
    }
}
