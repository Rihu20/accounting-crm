<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Matter;
use App\Models\Paralegal;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class WordService
{
    const HEADERS = ['Your Ref No', 'Our Ref No', 'App No', 'PCT No', 'Matter', 'Fee Type', 'Language', 'Amount', 'Currency'];

    const LEGACY_HEADERS = ['Matter Ref No', 'Fee Type', 'Matter / Action', 'Language', 'Amount', 'Currency'];

    public function parse(string $path, User $actor, ?string $layout = null, ?string $clientRef = null): array
    {
        $word = IOFactory::load($path);
        $tables = [];
        $text = '';
        foreach ($word->getSections() as $s) {
            foreach ($s->getElements() as $e) {
                if ($e instanceof Table) {
                    $tables[] = $e;
                } else {
                    $text .= $this->text($e).' ';
                }
            }
        }
        $out = ['errors' => [], 'rows' => [], 'client_id' => null, 'currency' => null, 'paralegal_id' => $actor->paralegal?->id, 'warnings' => [], 'invoice_layout' => null, 'missing_matters' => []];
        $documentClientRef = preg_match('/Client Ref No:\s*(\d{4})(?!\d)/i', $text, $clientMatch) ? $clientMatch[1] : null;
        $selectedClientRef = $clientRef ?: $documentClientRef;
        $selectedClient = null;
        if ($clientRef && $documentClientRef && $clientRef !== $documentClientRef) {
            $out['errors'][] = ['row' => 0, 'error' => 'The Client Ref No entered on screen differs from the Word file. Correct it before continuing.'];
        }
        if ($selectedClientRef) {
            $query = Client::where('ref_no', $selectedClientRef);
            if ($actor->hasRole('Paralegal')) {
                $query->whereIn('id', Matter::where('paralegal_id', $actor->paralegal?->id ?? 0)->select('client_id'));
            }
            $selectedClient = $query->first();
            if (! $selectedClient) {
                $out['errors'][] = ['row' => 0, 'error' => 'Client Ref No '.$selectedClientRef.' was not found or is not available to your account.'];
            }
        }
        $detected = null;
        if (preg_match('/Invoice Format:\s*(Combined|Detailed|India GST)\b/i', $text, $formatMatch)) {
            $detected = ['combined' => 'combined', 'detailed' => 'detailed', 'india gst' => 'india_gst'][strtolower($formatMatch[1])];
        }
        if ($layout && $detected && $layout !== $detected) {
            $out['errors'][] = ['row' => 0, 'error' => 'The selected format differs from Invoice Format in the Word file. Select the matching format or use Detect from Word.'];
        }
        $layout = $layout ?: $detected;
        if (count($tables) !== 1) {
            $out['errors'][] = ['row' => 0, 'error' => 'The Word file must contain exactly one table'];

            return $out;
        }
        $rows = $tables[0]->getRows();
        if (! count($rows)) {
            $out['errors'][] = ['row' => 1, 'error' => 'The Word table is empty. Use the supplied template and include its header row.'];

            return $out;
        }
        $normalizeHeader = fn (string $header): string => mb_strtolower(trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $header)));
        $headers = array_map(fn ($c) => $normalizeHeader($this->text($c)), $rows[0]->getCells());
        $legacyHeaders = self::LEGACY_HEADERS;
        $legacyHeaders[0] = 'Ref No';
        if ($headers !== array_map($normalizeHeader, self::HEADERS) && $headers !== array_map($normalizeHeader, self::LEGACY_HEADERS) && $headers !== array_map($normalizeHeader, $legacyHeaders)) {
            $out['errors'][] = ['row' => 1, 'error' => 'This is not a supported matter fee table. Download the Combined, Detailed or India GST template from this page. Required headers: '.implode(', ', self::HEADERS)];

            return $out;
        }
        if (preg_match('/Paralegal:\s*(.+?)(?:Date:|$)/u', $text, $m)) {
            $p = Paralegal::where('name', trim($m[1]))->first();
            if ($actor->hasRole('Paralegal')) {
                if ($p && $p->id !== $out['paralegal_id']) {
                    $out['warnings'][] = 'Paralegal name differs from uploader. Uploader account is used.';
                }
            } else {
                $out['paralegal_id'] = $p?->id;
            }
        }
        if (! $out['paralegal_id']) {
            $out['errors'][] = ['row' => 0, 'error' => 'Paralegal identity could not be matched'];
        }
        $clients = [];
        $currencies = [];
        $previousRef = '';
        $matterActions = [];
        $matterDetails = [];
        $proposedMatters = [];
        $lastApplications = [];
        if (preg_match('/Full Matter Ref:\s*([PDT]\d{8}[A-Z]{2})\b/i', $text, $referenceMatch)) {
            $previousRef = $referenceMatch[1];
        }
        foreach (array_slice($rows, 1) as $idx => $row) {
            $cells = array_map(fn ($c) => trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $this->text($c))), $row->getCells());
            if (! array_filter($cells)) {
                continue;
            }if (count($cells) !== count($headers)) {
                $out['errors'][] = ['row' => $idx + 2, 'error' => 'Expected '.count($headers).' cells'];

                continue;
            }
            $yourRef = $appNo = $pctNo = '';
            if (count($headers) === 9) {
                [$yourRef,$ref,$appNo,$pctNo,$action,$fee,$lang,$amount,$currency] = $cells;
            } else {
                [$ref,$fee,$action,$lang,$amount,$currency] = $cells;
            }
            foreach (['yourRef', 'appNo', 'pctNo', 'lang'] as $optionalField) {
                if (in_array(strtoupper(trim($$optionalField)), ['NA', 'N/A', 'N.A.', '-'], true)) {
                    $$optionalField = '';
                }
            }
            $ref = strtoupper(preg_replace('/\s/u', '', $ref));
            if ($ref === '' && $yourRef === '' && $appNo === '' && $pctNo === '' && $action === '' && $lang === '' && $amount === '') {
                continue;
            }
            if ($ref === '') {
                $ref = $previousRef;
            } else {
                $previousRef = $ref;
            }
            if ($fee === '' && $layout === 'combined' && $amount !== '') {
                $fee = 'Official + Professional Fees';
                $out['warnings'][] = 'Row '.($idx + 2).': Combined Official + Professional Fees applied from the selected invoice format.';
            }
            $errors = [];
            $referenceParts = MatterReference::parse($ref);
            if ($selectedClientRef && $referenceParts && $referenceParts['client_ref'] !== $selectedClientRef) {
                $errors[] = 'Our Ref No '.$ref.' does not belong to selected client '.$selectedClientRef.'.';
            }
            $matter = null;
            try {
                $matter = MatterReference::resolve($ref, $actor, $selectedClient?->id);
            } catch (ValidationException $e) {
                $parts = MatterReference::parse($ref);
                $client = $parts ? Client::where('ref_no', $parts['client_ref'])->first() : null;
                $exists = Matter::withTrashed()->where('our_ref', $ref)->exists();
                if ($parts && $client && (! $selectedClient || $selectedClient->id === $client->id) && ! $exists && $out['paralegal_id'] && $actor->allowed('accounting.masters.create') && $actor->allowed('accounting.invoices.create')) {
                    if ($action === '' && ! isset($proposedMatters[$ref])) {
                        $errors[] = 'Enter Matter / action for this new reference.';
                    } else {
                        $matter = $proposedMatters[$ref] ?? new Matter([
                            'our_ref' => $ref, 'ref_no' => $parts['client_ref'], 'client_id' => $client->id,
                            'paralegal_id' => $out['paralegal_id'], 'matter_title' => $action,
                            'country' => \Locale::getDisplayRegion('und_'.$parts['country_code'], 'en'),
                            'app_no' => $appNo ?: null, 'pct_no' => $pctNo ?: null, 'your_ref' => $yourRef ?: null,
                            'service_type' => in_array($fee, ['Official Fees', 'Professional Fees', 'Translation'], true) ? $fee : 'Professional Fees', 'status' => 'Active',
                        ]);
                        $proposedMatters[$ref] = $matter;
                    }
                } else {
                    $errors[] = $exists ? 'Matter '.$ref.' is unavailable or not assigned to you. Ask an administrator to check its assignment.' : 'Matter '.$ref.' is not in the master. Add it under Matters first'.($parts && ! $client ? '; client '.$parts['client_ref'].' must also exist in Clients.' : '.');
                }
            }if (! in_array($fee, InvoiceFormat::FEES)) {
                $errors[] = 'Fee Type is missing or invalid. Enter one of: '.implode(', ', InvoiceFormat::FEES);
            }if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
                $errors[] = 'Amount must be a number without symbols or commas, max 2 decimals';
            }
            if ($currency === '' && $matter) {
                $currency = $selectedClient?->default_currency ?: Client::find($matter->client_id)?->default_currency;
            }
            if (! in_array($currency, ['USD', 'INR', 'EUR'])) {
                $errors[] = 'Invalid currency';
            }if ($lang !== '' && $fee !== 'Translation') {
                $errors[] = 'Language is allowed only for Translation';
            }if ($matter && $actor->hasRole('Paralegal') && $matter->paralegal_id !== $actor->paralegal?->id) {
                $errors[] = 'This matter is not assigned to you';
            }
            $matterKey = $ref;
            if ($matter) {
                if ($appNo === '' && $pctNo === '') {
                    $details = $lastApplications[$ref] ?? ['app_no' => $matter->app_no, 'pct_no' => $matter->pct_no, 'your_ref' => $matter->your_ref];
                } else {
                    $details = [
                        'app_no' => $appNo !== '' ? $appNo : ($pctNo === $matter->pct_no ? $matter->app_no : null),
                        'pct_no' => $pctNo !== '' ? $pctNo : ($appNo === $matter->app_no ? $matter->pct_no : null),
                        'your_ref' => $matter->your_ref,
                    ];
                }
                $matterKey = $ref.'|'.json_encode([$details['app_no'] ?: null, $details['pct_no'] ?: null]);
                if (isset($matterDetails[$matterKey])) {
                    $details = $matterDetails[$matterKey];
                }
                foreach ([$appNo, $pctNo] as $identifier) {
                    if (mb_strlen($identifier) > 255) {
                        $errors[] = 'Matter identifiers must be 255 characters or fewer.';
                    }
                }
                if ($yourRef !== '') {
                    if (filled($details['your_ref']) && $yourRef !== $details['your_ref']) {
                        $out['warnings'][] = 'Your Ref No differs from the master for '.$matter->our_ref.'. Review it before confirming the invoice.';
                    }
                    $details['your_ref'] = $yourRef;
                }
                if (mb_strlen($yourRef) > 255) {
                    $errors[] = 'Your Ref No must be 255 characters or fewer.';
                }
                $clients[$matter->client_id][] = $ref;
                $currencies[$currency][] = $ref;
            }if ($errors) {
                $out['errors'][] = ['row' => $idx + 2, 'error' => implode('; ', $errors)];
            } else {
                $matterDetails[$matterKey] = $details;
                $lastApplications[$ref] = $details;
                $matterActions[$matterKey] = $action ?: ($matterActions[$matterKey] ?? $matter->matter_title);
                $out['rows'][] = ['ref_no' => $matter->ref_no, 'matter_ref' => $matter->our_ref ?: $matter->ref_no, 'your_ref' => $details['your_ref'], 'app_no' => $details['app_no'], 'pct_no' => $details['pct_no'], 'fee_type' => $fee, 'matter_text' => $matterActions[$matterKey], 'language' => $lang, 'rate' => $amount];
            }
        }
        if (count($clients) > 1) {
            $out['errors'][] = ['row' => 0, 'error' => 'Multiple clients: '.json_encode($clients)];
        }if (count($currencies) > 1) {
            $out['errors'][] = ['row' => 0, 'error' => 'Multiple currencies: '.json_encode($currencies)];
        }if (! $out['rows']) {
            $out['errors'][] = ['row' => 0, 'error' => 'No valid rows'];
        }$out['client_id'] = array_key_first($clients);
        $out['currency'] = array_key_first($currencies);
        if ($out['client_id']) {
            $out['client'] = Client::find($out['client_id'])?->only(['id', 'ref_no', 'name', 'contact_person', 'address', 'country', 'gstin', 'email_1', 'phone', 'default_currency']);
        }

        if ($out['client_id']) {
            try {
                $out['invoice_layout'] = InvoiceFormat::resolve($layout, Client::findOrFail($out['client_id']));
                InvoiceFormat::validateRows($out['invoice_layout'], $out['rows']);
            } catch (ValidationException $e) {
                foreach ($e->validator->errors()->all() as $error) {
                    $out['errors'][] = ['row' => 0, 'error' => $error];
                }
            }
        }

        foreach ($proposedMatters as $reference => $proposed) {
            if (in_array($reference, array_column($out['rows'], 'matter_ref'), true)) {
                $out['missing_matters'][] = $proposed->getAttributes();
                $out['warnings'][] = 'New matter '.$reference.' will be added to the master only when you confirm Create draft proforma.';
            }
        }

        return $out;
    }

    public function createMissingMatters(array $preview, User $actor): void
    {
        if (empty($preview['missing_matters'])) {
            return;
        }
        abort_unless($actor->allowed('accounting.masters.create') && $actor->allowed('accounting.invoices.create'), 403);
        foreach ($preview['missing_matters'] as $data) {
            Validator::make($data, ['our_ref' => 'required|regex:/^[PDT]\d{8}[A-Z]{2}$/', 'client_id' => 'required|exists:clients,id', 'paralegal_id' => 'required|exists:paralegals,id', 'matter_title' => 'required|string|max:255', 'country' => 'required|string|max:255', 'app_no' => 'nullable|string|max:255', 'pct_no' => 'nullable|string|max:255', 'your_ref' => 'nullable|string|max:255'])->validate();
            $parts = MatterReference::parse($data['our_ref']);
            $client = Client::findOrFail($preview['client_id']);
            if ($data['client_id'] !== $client->id || $parts['client_ref'] !== $client->ref_no || $data['paralegal_id'] !== $preview['paralegal_id']) {
                throw ValidationException::withMessages(['file' => 'The new matter does not match this client and paralegal. Validate the Word file again.']);
            }
            $existing = Matter::withTrashed()->where('our_ref', $data['our_ref'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->trashed() || $existing->client_id !== $client->id || $existing->paralegal_id !== $data['paralegal_id']) {
                    throw ValidationException::withMessages(['file' => 'Matter '.$data['our_ref'].' changed after preview. Validate the Word file again.']);
                }

                continue;
            }
            Matter::create(Arr::only($data, ['our_ref', 'ref_no', 'client_id', 'paralegal_id', 'matter_title', 'country', 'app_no', 'pct_no', 'your_ref', 'service_type', 'status']));
        }
    }

    private function text($element): string
    {
        if ($element instanceof TextBreak) {
            return ' ';
        }
        if (method_exists($element, 'getElements')) {
            return implode($element instanceof TextRun ? '' : ' ', array_map(fn ($e) => $this->text($e), $element->getElements()));
        }

        return method_exists($element, 'getText') ? (string) $element->getText() : '';
    }

    public function template(string $path, string $layout = 'detailed'): void
    {
        $names = ['combined' => 'Paralegal_Fees_Combined.docx', 'detailed' => 'Paralegal_Fees_Detailed.docx', 'india_gst' => 'Paralegal_Fees_India_GST.docx'];
        abort_unless(isset($names[$layout]), 422, 'Select a valid invoice format');
        $template = base_path('docs/'.$names[$layout]);
        if (is_file($template)) {
            copy($template, $path);

            return;
        }

        $w = new PhpWord;
        $s = $w->addSection();
        $s->addText('Paralegal: ');
        $s->addText('Date: '.today()->format('d.m.y'));
        $s->addText('Client Ref No: ____');
        $s->addText('Invoice Format: '.['combined' => 'Combined', 'detailed' => 'Detailed', 'india_gst' => 'India GST'][$layout]);
        $t = $s->addTable(['borderSize' => 6, 'cellMargin' => 100]);
        $t->addRow();
        foreach (self::HEADERS as $h) {
            $t->addCell(1700)->addText($h, ['bold' => true]);
        }$t->addRow();
        foreach (self::HEADERS as $h) {
            $t->addCell(1700)->addText('');
        }IOFactory::createWriter($w, 'Word2007')->save($path);
    }
}
