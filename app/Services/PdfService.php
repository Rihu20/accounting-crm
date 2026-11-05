<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PdfService
{
    public function pages(Invoice $invoice, ?array $viewData = null): array
    {
        $viewData ??= $this->viewData($invoice);
        $items = [];
        $groups = $invoice->items()->where('fee_type', '!=', 'Disbursement')->get()->groupBy(fn ($item) => $this->applicationKey($item).($invoice->invoice_layout === 'combined' ? '|'.$item->matter_text : ''));
        foreach ($groups->values() as $groupIndex => $group) {
            $ordered = $group->sortBy(fn ($item) => array_search($item->fee_type, ['Official Fees', 'Professional Fees', 'Translation', 'Official + Professional Fees'], true))->values();
            if ($invoice->invoice_layout === 'combined') {
                $combined = clone $ordered->first();
                $combined->amount = $ordered->reduce(fn (string $sum, InvoiceItem $item): string => bcadd($sum, $item->amount, 2), '0.00');
                $combined->rate = $combined->amount;
                $combined->qty = '1.00';
                $combined->fee_type = 'Official + Professional Fees';
                $ordered = collect([$combined]);
            }
            foreach ($ordered as $original) {
                $item = clone $original;
                $item->setAttribute('matter_index', $groupIndex + 1);
                $item->setAttribute('group_title', $ordered->first()->matter_text);
                $items[] = $item;
            }
        }

        $pages = [];
        $offset = 0;
        $brought = '0.00';
        do {
            $remaining = count($items) - $offset;
            $first = $pages === [];
            $final = $this->page(array_slice($items, $offset), $brought, $first, true);
            if ($this->fits($viewData, $final)) {
                $pages[] = $final;
                break;
            }
            if ($remaining === 0) {
                throw ValidationException::withMessages(['items' => 'Invoice totals and payment instructions exceed one page. Shorten the instructions or charge labels.']);
            }

            $low = 1;
            $high = $remaining;
            $fit = 0;
            while ($low <= $high) {
                $count = intdiv($low + $high, 2);
                $candidate = $this->page(array_slice($items, $offset, $count), $brought, $first, false);
                if ($this->fits($viewData, $candidate)) {
                    $fit = $count;
                    $low = $count + 1;
                } else {
                    $high = $count - 1;
                }
            }
            if ($fit === 0) {
                throw ValidationException::withMessages(['items' => 'A matter description is too long to fit on an invoice page. Shorten its text or identifiers.']);
            }

            // Keep a complete matter together when it fits; oversized matters continue with their context repeated.
            if ($offset + $fit < count($items)) {
                $nextMatter = $items[$offset + $fit]->matter_index;
                $boundary = $fit;
                while ($boundary > 0 && $items[$offset + $boundary - 1]->matter_index === $nextMatter) {
                    $boundary--;
                }
                if ($boundary > 0) {
                    $fit = $boundary;
                }
            }
            $page = $this->page(array_slice($items, $offset, $fit), $brought, $first, false);
            $pages[] = $page;
            $brought = $page['carried'];
            $offset += $fit;
        } while (true);

        return $pages;
    }

    private function page(array $items, string $brought, bool $first, bool $last): array
    {
        $previous = null;
        $carried = $brought;
        $rows = [];
        foreach ($items as $original) {
            $item = clone $original;
            $item->setAttribute('show_matter', $previous !== $item->matter_index);
            $previous = $item->matter_index;
            $carried = bcadd($carried, $item->amount, 2);
            $rows[] = $item;
        }

        return ['items' => $rows, 'brought' => $brought, 'carried' => $carried, 'first' => $first, 'last' => $last];
    }

    private function fits(array $viewData, array $page): bool
    {
        $pdf = $this->document($viewData + ['pages' => [$page]]);
        $pdf->render();

        return $pdf->getDomPDF()->getCanvas()->get_page_count() === 1;
    }

    private function viewData(Invoice $invoice): array
    {
        return ['invoice' => $invoice->load(['items', 'client', 'bankAccount', 'gateway']), 'logo' => $this->image('logo'), 'signature' => $this->image('signature'), 'settings' => Setting::pluck('value', 'key')->all()];
    }

    private function document(array $viewData): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('pdf.invoice', $viewData)->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    }

    private function applicationKey(InvoiceItem $item): string
    {
        return json_encode([$item->matter_id ?: ($item->our_ref ?: $item->ref_no), $item->app_no ?: null, $item->pct_no ?: null]);
    }

    public function image(string $key): ?string
    {
        $path = Setting::get($key.'_path');
        $disk = Storage::disk(config('crm.disk'));
        if (! $path || ! $disk->exists($path)) {
            return null;
        }$mime = $disk->mimeType($path);
        if (! in_array($mime, ['image/png', 'image/jpeg'])) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    public function render(Invoice $invoice): string
    {
        $viewData = $this->viewData($invoice);

        return $this->document($viewData + ['pages' => $this->pages($invoice, $viewData)])->output();
    }

    public function save(Invoice $invoice): void
    {
        $name = $invoice->number ?? ('draft-'.$invoice->id);
        $path = ($invoice->doc_type === 'tax' ? 'Invoices' : 'Proformas').'/'.($invoice->paralegal_id ?? 'Admin').'/'.$name.'.pdf';
        Storage::disk(config('crm.disk'))->put($path, $this->render($invoice));
        $invoice->update(['pdf_path' => $path]);
    }
}
