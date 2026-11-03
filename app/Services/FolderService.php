<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Invoice;
use App\Models\StorageFolder;
use Illuminate\Support\Facades\Storage;

class FolderService
{
    public function ensureForTaxInvoice(Invoice $invoice): void
    {
        $disk = Storage::disk(config('crm.disk'));
        foreach ($invoice->items()->with('matter.paralegal')->get()->pluck('matter')->filter()->unique('id') as $matter) {
            $name = preg_replace('/[^\pL\pN _-]/u', '_', $matter->paralegal->name);
            $path = 'Paralegals/'.$name.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $matter->our_ref ?: $matter->ref_no).'/'.$invoice->invoice_date->format('d.m.y');
            $folder = StorageFolder::firstOrCreate(['matter_id' => $matter->id, 'paralegal_id' => $matter->paralegal_id, 'ref_no' => $matter->ref_no, 'folder_date' => $invoice->invoice_date->format('Y-m-d')], ['storage_path' => $path]);
            if (! $disk->exists($path)) {
                $disk->makeDirectory($path);
            }$file = $path.'/'.$invoice->number.'.pdf';
            $disk->put($file, $disk->get($invoice->pdf_path));
            Document::firstOrCreate(['invoice_id' => $invoice->id, 'matter_id' => $matter->id, 'path' => $file], ['storage_folder_id' => $folder->id, 'type' => 'tax_invoice', 'name' => $invoice->number.'.pdf', 'uploaded_by' => $invoice->created_by]);
        }
    }
}
