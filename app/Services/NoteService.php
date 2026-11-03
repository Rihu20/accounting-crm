<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceNote;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/** Credit / Debit notes against an issued tax invoice, numbered per FY (CN260001 / DN260001) with GST. */
class NoteService
{
    public function create(Invoice $invoice, array $data, User $actor): InvoiceNote
    {
        abort_unless($actor->is_active && $actor->allowed('accounting.notes.create'), 403);
        $money = ['nullable', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'];
        $data = Validator::make($data, [
            'type' => 'required|in:credit,debit',
            'note_date' => 'required|date',
            'taxable_amount' => $money,
            'non_taxable_amount' => $money,
            'reason' => 'required|string|max:2000',
        ])->validate();
        $taxable = (string) ($data['taxable_amount'] ?? '0');
        $other = (string) ($data['non_taxable_amount'] ?? '0');

        return DB::transaction(function () use ($invoice, $data, $actor, $taxable, $other) {
            $invoice = Invoice::with('client')->lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->doc_type === 'tax' && $invoice->status === 'Issued', 422, 'Notes can only be raised against an issued tax invoice.');
            abort_unless(bccomp(bcadd($taxable, $other, 2), '0', 2) > 0, 422, 'Enter the note amount.');
            abort_if($invoice->invoice_date && \Illuminate\Support\Carbon::parse($data['note_date'])->lt($invoice->invoice_date), 422, 'Note date cannot be before the tax invoice date.');

            $indian = $invoice->client->client_type === 'Indian';
            $rate = $indian ? (string) Setting::get('gst_rate', '18') : '0';
            $gst = $indian ? Money::mul($taxable, bcdiv($rate, '100', 8)) : '0.00';
            $intra = Gst::intraState($invoice->client);
            $cgst = $intra ? Money::round(bcdiv($gst, '2', 4)) : '0.00';
            $sgst = $intra ? bcsub($gst, $cgst, 2) : '0.00';
            $igst = $intra ? '0.00' : $gst;

            if ($data['type'] === 'credit') {
                $already = (string) $invoice->notes()->where('type', 'credit')->sum('amount');
                $total = bcadd(bcadd($taxable, $other, 2), $gst, 2);
                abort_if(bccomp(bcadd($already, $total, 2), (string) $invoice->grand_total, 2) > 0, 422, 'Credit notes cannot exceed the tax invoice value ('.$invoice->grand_total.' '.$invoice->currency.').');
            }

            $series = $data['type'] === 'credit' ? 'credit_note' : 'debit_note';
            $note = InvoiceNote::create([
                'invoice_id' => $invoice->id,
                'type' => $data['type'],
                'number' => app(InvoiceService::class)->nextNumber($series, $data['note_date'], $data['type'] === 'credit' ? 'CN' : 'DN'),
                'note_date' => $data['note_date'],
                'currency' => $invoice->currency,
                'taxable_amount' => $taxable,
                'non_taxable_amount' => $other,
                'gst_rate' => $rate,
                'igst' => $igst, 'cgst' => $cgst, 'sgst' => $sgst,
                'amount' => bcadd(bcadd($taxable, $other, 2), $gst, 2),
                'reason' => $data['reason'],
                'created_by' => $actor->id,
            ]);
            $this->save($note);

            return $note->fresh();
        });
    }

    public function render(InvoiceNote $note): string
    {
        $note->loadMissing('invoice.client');

        return Pdf::loadView('pdf.note', [
            'note' => $note,
            'invoice' => $note->invoice,
            'settings' => Setting::pluck('value', 'key')->all(),
            'logo' => app(PdfService::class)->image('logo'),
            'signature' => app(PdfService::class)->image('signature'),
        ])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
    }

    public function save(InvoiceNote $note): void
    {
        $path = 'Notes/'.$note->number.'.pdf';
        Storage::disk(config('crm.disk'))->put($path, $this->render($note));
        $note->update(['pdf_path' => $path]);
    }
}
