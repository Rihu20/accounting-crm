<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MasterImportService
{
    const MAP = [
        'Clients' => ['Client Name' => 'name', 'Client Type' => 'client_type', 'Country' => 'country', 'State (India)' => 'state', 'Contact Person' => 'contact_person', 'Email 1' => 'email_1', 'Email 2' => 'email_2', 'Phone' => 'phone', 'Address' => 'address', 'GSTIN (India)' => 'gstin', 'Default Currency' => 'default_currency', 'Notes' => 'notes', 'Ref No' => 'ref_no'],
        'Paralegals' => ['Paralegal Name' => 'name', 'Email' => 'email', 'Phone' => 'phone', 'Firm / Company' => 'firm'],
        'Vendors' => ['Vendor Name' => 'name', 'Vendor Type' => 'vendor_type', 'Country' => 'country', 'Email' => 'email', 'GSTIN / Tax ID' => 'tax_id', 'Default Currency' => 'default_currency', 'Notes' => 'notes'],
        'Matters' => ['Ref No' => 'ref_no', 'Client Name' => 'client_name', 'Our Ref (Docket No)' => 'our_ref', 'Your Ref' => 'your_ref', 'Matter Title' => 'matter_title', 'Country' => 'country', 'App No' => 'app_no', 'PCT No' => 'pct_no', 'Service Type' => 'service_type', 'Language' => 'language', 'Paralegal' => 'paralegal_name', 'Status' => 'status']];

    public function preview(string $path): array
    {
        $book = IOFactory::load($path);
        $out = ['new' => 0, 'updated' => 0, 'rejected' => 0, 'skipped' => 0, 'rows' => [], 'errors' => []];
        $clients = Client::pluck('name')->all();
        $paralegals = Paralegal::pluck('name')->all();
        $clientRefs = Client::whereNotNull('ref_no')->pluck('ref_no', 'name')->all();
        $seenMatters = [];
        $seenMasterKeys = [];
        foreach (self::MAP as $sheet => $map) {
            $tab = $book->getSheetByName($sheet);
            if (! $tab) {
                $out['errors'][] = ['sheet' => $sheet, 'row' => 1, 'error' => 'Missing sheet'];
                $out['rejected']++;

                continue;
            }
            $headers = $tab->rangeToArray('A1:'.$tab->getHighestColumn().'1', null, false, false)[0];
            if ($sheet === 'Clients' && ($headers[count($map) - 1] ?? null) !== 'Ref No') {
                $map = array_slice($map, 0, -1, true);
            }
            if (array_slice($headers, 0, count($map)) !== array_keys($map)) {
                $out['errors'][] = ['sheet' => $sheet, 'row' => 1, 'error' => 'Headers must match the supplied master workbook'];
                $out['rejected']++;

                continue;
            }
            for ($r = 2; $r <= $tab->getHighestDataRow(); $r++) {
                $values = [];
                foreach (array_values($map) as $c => $field) {
                    $cell = $tab->getCell([$c + 1, $r]);
                    $raw = $cell->getValue();
                    $values[$field] = trim((string) $raw);
                    if ($field === 'ref_no' && is_numeric($raw) && preg_match('/^0{4}$/', $cell->getStyle()->getNumberFormat()->getFormatCode())) {
                        $values[$field] = str_pad((string) $raw, 4, '0', STR_PAD_LEFT);
                    }
                }if (! array_filter($values, fn ($v) => $v !== '')) {
                    continue;
                }if (($sheet === 'Matters' && ($values['ref_no'] ?? '') === '0000') || str_starts_with($values['name'] ?? '', 'Example ')) {
                    $out['skipped']++;

                    continue;
                }
                $parts = null;
                if ($sheet === 'Matters') {
                    $values['our_ref'] = strtoupper($values['our_ref']);
                    $parts = MatterReference::parse($values['our_ref']);
                    if ($parts && $values['ref_no'] === '') {
                        $values['ref_no'] = $parts['client_ref'];
                    }
                }
                $rules = match ($sheet) {
                    'Clients' => ['name' => 'required|max:255', 'client_type' => 'required|in:Foreign,Indian', 'country' => 'required', 'default_currency' => 'required|in:USD,INR,EUR', 'email_1' => 'nullable|email', 'email_2' => 'nullable|email', 'ref_no' => ['nullable', 'regex:/^\d{4}$/']],'Paralegals' => ['name' => 'required', 'email' => 'nullable|email'],'Vendors' => ['name' => 'required', 'vendor_type' => 'required|in:Foreign,Indian', 'country' => 'required', 'default_currency' => 'required|in:USD,INR,EUR', 'email' => 'nullable|email'],default => ['ref_no' => ['required', 'regex:/^\d{4}$/'], 'matter_title' => 'required', 'country' => 'required', 'service_type' => 'required|in:Official Fees,Professional Fees,Translation', 'status' => 'required|in:Active,Closed,On Hold']
                };
                $errors = Validator::make($values, $rules)->errors()->all();
                if ($sheet === 'Clients' && ! empty($values['ref_no'])) {
                    $owner = array_search($values['ref_no'], $clientRefs, true);
                    if ($owner !== false && $owner !== $values['name']) {
                        $errors[] = 'Ref No already belongs to client '.$owner;
                    }
                    if (isset($clientRefs[$values['name']]) && $clientRefs[$values['name']] !== $values['ref_no'] && Matter::whereHas('client', fn ($q) => $q->where('name', $values['name']))->exists()) {
                        $errors[] = 'Existing linked matters use a different client Ref No';
                    }
                }
                if ($sheet === 'Matters') {
                    if (! $parts && ! Matter::where('our_ref', $values['our_ref'])->whereNotNull('our_ref')->exists()) {
                        $errors[] = 'Enter a full matter reference such as P25124809KR';
                    }
                    if ($parts && $parts['client_ref'] !== $values['ref_no']) {
                        $errors[] = 'Ref No must match the client reference inside Our Ref (Docket No)';
                    }
                    if (isset($clientRefs[$values['client_name']]) && $values['ref_no'] !== $clientRefs[$values['client_name']]) {
                        $errors[] = 'Ref No does not match the selected client';
                    }
                    $identifier = $values['our_ref'] ?: $values['ref_no'];
                    if (isset($seenMatters[$identifier])) {
                        $errors[] = 'Duplicate matter reference in this workbook';
                    }
                    if (! $values['our_ref'] && Matter::where('ref_no', $values['ref_no'])->count() > 1) {
                        $errors[] = 'Enter the full matter reference; this client has multiple matters';
                    }
                    if ($values['our_ref'] && Matter::where('our_ref', $values['our_ref'])->whereHas('client', fn ($q) => $q->where('name', '!=', $values['client_name']))->exists()) {
                        $errors[] = 'This matter reference belongs to another client';
                    }
                    if (! in_array($values['client_name'], $clients)) {
                        $errors[] = 'Client Name does not exist';
                    }if (! in_array($values['paralegal_name'], $paralegals)) {
                        $errors[] = 'Paralegal does not exist';
                    }
                }
                [$identityModel,$identityKey] = $this->key($sheet, $values);
                $identity = $sheet.'|'.$identityKey.'|'.mb_strtolower($values[$identityKey]);
                if (isset($seenMasterKeys[$identity])) {
                    $errors[] = 'Duplicate '.$identityKey.' in this workbook';
                }
                if ($errors) {
                    $out['rejected']++;
                    $out['errors'][] = ['sheet' => $sheet, 'row' => $r, 'error' => implode('; ', $errors)];

                    continue;
                }
                $seenMasterKeys[$identity] = true;
                if ($sheet === 'Clients') {
                    $clients[] = $values['name'];
                    if (! empty($values['ref_no'])) {
                        $clientRefs[$values['name']] = $values['ref_no'];
                    }
                }if ($sheet === 'Paralegals') {
                    $paralegals[] = $values['name'];
                }
                if ($sheet === 'Matters') {
                    $seenMatters[$values['our_ref'] ?: $values['ref_no']] = true;
                }
                [$model,$key] = $this->key($sheet, $values);
                $exists = $model::where($key, $values[$key])->exists();
                $out[$exists ? 'updated' : 'new']++;
                $out['rows'][] = ['sheet' => $sheet, 'row' => $r, 'data' => $values];
            }
        }

        return $out;
    }

    private function key(string $sheet, array $d): array
    {
        return match ($sheet) {
            'Clients' => [Client::class, 'name'],'Paralegals' => [Paralegal::class, ! empty($d['email']) ? 'email' : 'name'],'Vendors' => [Vendor::class, 'name'],default => [Matter::class, ! empty($d['our_ref']) ? 'our_ref' : 'ref_no']
        };
    }

    public function commit(array $preview): void
    {
        abort_if($preview['rejected'] > 0, 422, 'Fix rejected rows before importing');
        DB::transaction(function () use ($preview) {
            foreach ($preview['rows'] as $row) {
                $d = $row['data'];
                if ($row['sheet'] === 'Clients' && empty($d['ref_no'])) {
                    unset($d['ref_no']);
                }
                [$model,$key] = $this->key($row['sheet'], $d);
                if ($row['sheet'] === 'Matters') {
                    $d['client_id'] = Client::where('name', $d['client_name'])->firstOrFail()->id;
                    $d['paralegal_id'] = Paralegal::where('name', $d['paralegal_name'])->firstOrFail()->id;
                    unset($d['client_name'],$d['paralegal_name']);
                }$model::updateOrCreate([$key => $d[$key]], array_map(fn ($v) => $v === '' ? null : $v, $d));
            }
        });
    }
}
