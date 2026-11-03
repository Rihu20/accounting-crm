<?php

namespace App\Services;

use App\Models\InvoiceDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountingFileService
{
    public function archive(array $paths, array $data, User $actor): array
    {
        abort_unless($actor->allowed('accounting.invoices.create'), 403);
        $data = Validator::make($data, ['doc_type' => 'required|in:proforma,tax', 'client_id' => 'nullable|exists:clients,id', 'invoice_date' => 'nullable|date', 'period_year' => 'nullable|integer|between:1900,2100|required_with:period_month', 'period_month' => 'nullable|integer|between:1,12|required_with:period_year', 'notes' => 'nullable|string|max:4000', 'file_names' => 'nullable|array'])->validate();
        if (! count($paths) || count($paths) > 20) {
            throw ValidationException::withMessages(['files' => 'Select between 1 and 20 PDF files per batch.']);
        }
        $disk = Storage::disk(config('crm.disk'));
        $checks = [];
        $seen = [];
        foreach ($paths as $path) {
            if (! is_string($path) || ! preg_match('#^InvoiceUploads/'.preg_quote((string) $actor->id, '#').'/[A-Za-z0-9/_-]+\.pdf$#i', $path) || ! $disk->exists($path)) {
                throw ValidationException::withMessages(['files' => 'The uploaded PDF path is invalid. Upload the file again.']);
            }
            $stream = $disk->readStream($path);
            $context = hash_init('sha256');
            $head = fread($stream, 1024);
            rewind($stream);
            hash_update_stream($context, $stream);
            fclose($stream);
            $hash = hash_final($context);
            if (! str_contains($head, '%PDF-') || $disk->size($path) > 25 * 1024 * 1024) {
                throw ValidationException::withMessages(['files' => 'Each file must be a PDF up to 25 MB.']);
            }
            if (isset($seen[$hash]) || InvoiceDocument::where('sha256', $hash)->exists()) {
                throw ValidationException::withMessages(['files' => 'This PDF is already uploaded. Each document is kept once.']);
            }
            $seen[$hash] = true;
            $checks[] = ['path' => $path, 'sha256' => $hash, 'file_name' => mb_substr(basename($data['file_names'][$path] ?? $path), 0, 255)];
        }
        unset($data['file_names']);
        $data = array_map(fn ($value) => $value === '' ? null : $value, $data);

        return DB::transaction(function () use ($checks, $data, $actor) {
            $out = [];
            foreach ($checks as $file) {
                $out[] = InvoiceDocument::create($data + $file + ['uploaded_by' => $actor->id]);
            }

return $out;
        });
    }
}
