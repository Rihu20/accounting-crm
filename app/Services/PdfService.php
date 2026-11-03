<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfService
{
    public function pages(Invoice $invoice): array
    {
        $pages = [];
        $page = [];
        $units = 0;
        $running = '0.00';
        $brought = '0.00';
        $lastMatter = null;
        $groups = $invoice->items()->where('fee_type', '!=', 'Disbursement')->get()->groupBy(fn ($item) => $this->applicationKey($item).($invoice->invoice_layout === 'combined' ? '|'.$item->matter_text : ''));
        $flush = function () use (&$pages, &$page, &$units, &$running, &$brought, &$lastMatter): void {
            $pages[] = ['items' => $page, 'brought' => $brought, 'carried' => $running, 'last' => false];
            $page = [];
            $units = 0;
            $brought = $running;
            $lastMatter = null;
        };
        foreach ($groups->values() as $groupIndex => $group) {
            $ordered = $group->sortBy(fn ($item) => array_search($item->fee_type, ['Official Fees', 'Professional Fees', 'Translation', 'Official + Professional Fees'], true))->values();
            if ($invoice->invoice_layout === 'combined') {
                $combined = clone $ordered->first();
                $amount = '0.00';
                foreach ($ordered as $item) {
                    $amount = bcadd($amount, $item->amount, 2);
                }
                $combined->amount = $amount;
                $combined->rate = $amount;
                $combined->qty = '1.00';
                $combined->fee_type = 'Official + Professional Fees';
                $ordered = collect([$combined]);
            }
            $contextLines = 3 + ($ordered->first()->app_no ? 1 : 0) + ($ordered->first()->pct_no ? 1 : 0) + ($ordered->first()->your_ref ? 1 : 0) + (int) ceil(mb_strlen($ordered->first()->matter_text) / 48);
            $blockLines = $invoice->invoice_layout === 'combined' ? $contextLines + 2 : $contextLines + $ordered->sum(fn ($item) => 2 + (int) ceil(mb_strlen($item->matter_text) / 80));
            if ($page && $blockLines <= 24 && $units + $blockLines > 24) {
                $flush();
            }
            foreach ($ordered as $original) {
                $item = clone $original;
                $key = $this->applicationKey($item);
                $showMatter = $lastMatter !== $key;
                $lines = $invoice->invoice_layout === 'combined' ? $contextLines + 2 : 2 + (int) ceil(mb_strlen($item->matter_text) / 80) + ($showMatter ? $contextLines : 0);
                if ($page && $units + $lines > 24) {
                    $flush();
                    $showMatter = true;
                    $lines = $invoice->invoice_layout === 'combined' ? $contextLines + 2 : 2 + (int) ceil(mb_strlen($item->matter_text) / 80) + $contextLines;
                }
                $item->setAttribute('show_matter', $showMatter);
                $item->setAttribute('matter_index', $groupIndex + 1);
                $item->setAttribute('group_title', $ordered->first()->matter_text);
                $page[] = $item;
                $units += $lines;
                $running = bcadd($running, $item->amount, 2);
                $lastMatter = $key;
            }
        }
        if ($page || ! $pages) {
            $pages[] = ['items' => $page, 'brought' => $brought, 'carried' => $running, 'last' => true];
        } else {
            $pages[array_key_last($pages)]['last'] = true;
        }
        if ($units > max(2, 18 - count($invoice->additional_charges ?? []) * 2)) {
            $pages[array_key_last($pages)]['last'] = false;
            $pages[] = ['items' => [], 'brought' => $running, 'carried' => $running, 'last' => true];
        }

        return $pages;
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
        return Pdf::loadView('pdf.invoice', ['invoice' => $invoice->load(['items', 'client', 'bankAccount', 'gateway']), 'logo' => $this->image('logo'), 'signature' => $this->image('signature'), 'pages' => $this->pages($invoice), 'settings' => Setting::pluck('value', 'key')->all()])->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
    }

    public function save(Invoice $invoice): void
    {
        $name = $invoice->number ?? ('draft-'.$invoice->id);
        $path = ($invoice->doc_type === 'tax' ? 'Invoices' : 'Proformas').'/'.($invoice->paralegal_id ?? 'Admin').'/'.$name.'.pdf';
        Storage::disk(config('crm.disk'))->put($path, $this->render($invoice));
        $invoice->update(['pdf_path' => $path]);
    }
}
