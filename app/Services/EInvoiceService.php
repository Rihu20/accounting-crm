<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * e-Invoice for a tax invoice: the JSON in the GST e-invoice schema (INV-01, version 1.1) to upload on the
 * Invoice Registration Portal, and the IRN, acknowledgement and signed QR the portal returns.
 * The CRM does not call the IRP itself; that needs a GSP account.
 */
class EInvoiceService
{
    /** ISO country codes for the export details; a country not listed is left out (the field is optional). */
    private const COUNTRY_CODES = ['united states' => 'US', 'usa' => 'US', 'united kingdom' => 'GB', 'uk' => 'GB', 'germany' => 'DE', 'france' => 'FR', 'japan' => 'JP', 'china' => 'CN', 'south korea' => 'KR', 'korea' => 'KR', 'taiwan' => 'TW', 'colombia' => 'CO', 'canada' => 'CA', 'australia' => 'AU', 'singapore' => 'SG', 'switzerland' => 'CH', 'netherlands' => 'NL', 'italy' => 'IT', 'spain' => 'ES', 'sweden' => 'SE', 'israel' => 'IL', 'united arab emirates' => 'AE', 'uae' => 'AE', 'brazil' => 'BR', 'mexico' => 'MX', 'russia' => 'RU', 'south africa' => 'ZA', 'new zealand' => 'NZ', 'ireland' => 'IE', 'belgium' => 'BE', 'denmark' => 'DK', 'finland' => 'FI', 'norway' => 'NO', 'austria' => 'AT', 'poland' => 'PL', 'hong kong' => 'HK', 'malaysia' => 'MY', 'thailand' => 'TH', 'vietnam' => 'VN', 'indonesia' => 'ID', 'philippines' => 'PH', 'saudi arabia' => 'SA', 'turkey' => 'TR'];

    public static function canUse(Invoice $invoice, User $user): bool
    {
        return $invoice->doc_type === 'tax' && $invoice->status !== 'Cancelled' && $user->allowed('accounting.tax.issue');
    }

    /**
     * The invoice in the e-invoice schema. Amounts are in rupees: a foreign invoice uses its INR rate from Register details.
     *
     * @return array<string, mixed>
     */
    public function payload(Invoice $invoice): array
    {
        $invoice->loadMissing('items');
        $client = $invoice->billingClient();
        $export = Gst::isExport($client);
        $problems = [];
        $rate = $invoice->currency === 'INR' ? '1' : (string) ($invoice->inr_exchange_rate ?? '');
        if ($invoice->doc_type !== 'tax' || ! $invoice->number) {
            $problems[] = 'Only an issued tax invoice can be registered.';
        }
        if ($invoice->currency !== 'INR' && bccomp($rate ?: '0', '0', 8) <= 0) {
            $problems[] = 'Enter the INR exchange rate in Register details first (the e-invoice is in rupees).';
        }
        $sellerGstin = strtoupper(trim((string) Setting::get('company_gstin', '')));
        $sellerAddress = (string) Setting::get('company_address', '');
        $sellerPin = self::pin($sellerAddress);
        if (! preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $sellerGstin)) {
            $problems[] = 'Company GSTIN in Settings is missing or not valid.';
        }
        if (! $sellerPin) {
            $problems[] = 'Add the 6-digit PIN code to the company address in Settings.';
        }
        $buyerPin = $export ? 999999 : self::pin((string) $client->address);
        if (! $export) {
            if (blank($client->gstin)) {
                $problems[] = 'The client has no GSTIN. e-Invoices are only for registered buyers and exports.';
            }
            if (! $buyerPin) {
                $problems[] = 'Add the 6-digit PIN code to the client address.';
            }
        }
        if ($problems) {
            throw ValidationException::withMessages(['einvoice' => implode(' ', $problems)]);
        }

        $inr = fn (string $amount): float => (float) Money::mul($amount, $rate);
        $intra = Gst::intraState($client);
        $taxed = ! $export && $invoice->gst_enabled;
        $items = [];
        $assTotal = 0.0;
        $igstTotal = 0.0;
        $cgstTotal = 0.0;
        $sgstTotal = 0.0;
        foreach ($invoice->items->where('fee_type', '!=', 'Disbursement')->values() as $n => $item) {
            /** @var InvoiceItem $item */
            $amount = $inr((string) $item->amount);
            $gstRate = $taxed && $item->fee_type === 'Professional Fees' ? (float) $item->tax_rate : 0.0;
            $gst = round($amount * $gstRate / 100, 2);
            $cgst = $intra ? round($gst / 2, 2) : 0.0;
            $sgst = $intra ? round($gst - $cgst, 2) : 0.0;
            $igst = $intra ? 0.0 : $gst;
            $items[] = [
                'SlNo' => (string) ($n + 1), 'PrdDesc' => mb_substr(trim($item->fee_type.' – '.$item->matter_text), 0, 300), 'IsServc' => 'Y', 'HsnCd' => (string) ($item->sac_code ?: '998213'),
                'Qty' => (float) $item->qty, 'Unit' => 'OTH', 'UnitPrice' => $inr((string) $item->rate), 'TotAmt' => $amount, 'Discount' => 0, 'AssAmt' => $amount,
                'GstRt' => $gstRate, 'IgstAmt' => $igst, 'CgstAmt' => $cgst, 'SgstAmt' => $sgst, 'TotItemVal' => round($amount + $gst, 2),
            ];
            $assTotal += $amount;
            $igstTotal += $igst;
            $cgstTotal += $cgst;
            $sgstTotal += $sgst;
        }
        $other = $inr(bcadd((string) $invoice->disbursement_total, (string) ($invoice->additional_charges_total ?? '0'), 2));
        $total = $inr((string) $invoice->grand_total);
        // Keep the totals equal to the invoice: any paisa difference from rounding goes to the round-off.
        $roundOff = round($total - ($assTotal + $igstTotal + $cgstTotal + $sgstTotal + $other), 2);

        $payload = [
            'Version' => '1.1',
            'TranDtls' => ['TaxSch' => 'GST', 'SupTyp' => $export ? 'EXPWOP' : 'B2B', 'RegRev' => 'N', 'IgstOnIntra' => 'N'],
            'DocDtls' => ['Typ' => 'INV', 'No' => $invoice->number, 'Dt' => $invoice->invoice_date->format('d/m/Y')],
            'SellerDtls' => ['Gstin' => $sellerGstin, 'LglNm' => (string) Setting::get('company_name', ''), 'Addr1' => mb_substr($sellerAddress, 0, 100), 'Loc' => (string) (Setting::get('company_state') ?: 'India'), 'Pin' => $sellerPin, 'Stcd' => substr($sellerGstin, 0, 2), 'Em' => (string) Setting::get('company_email', '')],
            'BuyerDtls' => $export
                ? ['Gstin' => 'URP', 'LglNm' => $client->name, 'Pos' => '96', 'Addr1' => mb_substr((string) ($client->address ?: $client->country), 0, 100), 'Loc' => mb_substr((string) ($client->country ?: 'Outside India'), 0, 50), 'Pin' => 999999, 'Stcd' => '96']
                : ['Gstin' => strtoupper((string) $client->gstin), 'LglNm' => $client->name, 'Pos' => Gst::stateCode($client), 'Addr1' => mb_substr((string) $client->address, 0, 100), 'Loc' => mb_substr((string) ($client->state ?: 'India'), 0, 50), 'Pin' => $buyerPin, 'Stcd' => Gst::stateCode($client)],
            'ItemList' => $items,
            'ValDtls' => ['AssVal' => round($assTotal, 2), 'CgstVal' => round($cgstTotal, 2), 'SgstVal' => round($sgstTotal, 2), 'IgstVal' => round($igstTotal, 2), 'OthChrg' => $other, 'RndOffAmt' => $roundOff, 'TotInvVal' => $total] + ($export ? ['TotInvValFc' => (float) $invoice->grand_total] : []),
        ];
        if ($export) {
            $country = self::COUNTRY_CODES[mb_strtolower(trim((string) $client->country))] ?? null;
            $payload['ExpDtls'] = ['ForCur' => $invoice->currency] + ($country ? ['CntCode' => $country] : []);
        }

        return $payload;
    }

    /** The JSON file as the portal's bulk upload expects it: a list of invoices. */
    public function json(Invoice $invoice): string
    {
        return json_encode([$this->payload($invoice)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Keeps what the IRP returned: either its response JSON pasted as it is, or the IRN, acknowledgement and signed QR typed in.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Invoice $invoice, array $data, User $actor): void
    {
        abort_unless(self::canUse($invoice, $actor), 403);
        $response = trim((string) ($data['response'] ?? ''));
        if ($response !== '') {
            $decoded = json_decode($response, true);
            $decoded = is_array($decoded) && array_is_list($decoded) ? ($decoded[0] ?? null) : $decoded;
            $decoded = is_array($decoded) && isset($decoded['Data']) && is_array($decoded['Data']) ? $decoded['Data'] : $decoded;
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['response' => 'This is not the JSON the IRP returned.']);
            }
            $data = ['irn' => $decoded['Irn'] ?? null, 'ack_no' => $decoded['AckNo'] ?? null, 'ack_date' => $decoded['AckDt'] ?? null, 'signed_qr' => $decoded['SignedQRCode'] ?? null];
        }
        $irn = strtolower(trim((string) ($data['irn'] ?? '')));
        if (! preg_match('/^[0-9a-f]{64}$/', $irn)) {
            throw ValidationException::withMessages(['irn' => 'The IRN is the 64-character code the IRP returns.']);
        }
        if (Invoice::where('irn', $irn)->whereKeyNot($invoice->getKey())->exists()) {
            throw ValidationException::withMessages(['irn' => 'This IRN is already recorded on another invoice.']);
        }
        if (blank($data['ack_no'] ?? null) || blank($data['ack_date'] ?? null)) {
            throw ValidationException::withMessages(['ack_no' => 'Enter the acknowledgement number and date from the IRP.']);
        }

        $invoice->forceFill([
            'irn' => $irn, 'irn_ack_no' => trim((string) $data['ack_no']), 'irn_ack_date' => Carbon::parse((string) $data['ack_date']),
            'irn_signed_qr' => filled($data['signed_qr'] ?? null) ? trim((string) $data['signed_qr']) : null,
        ])->saveQuietly();
        activity()->causedBy($actor)->performedOn($invoice)->withProperties(['irn' => substr($irn, 0, 12).'…', 'ack_no' => $invoice->irn_ack_no])->log('e-Invoice IRN recorded');
        app(PdfService::class)->save($invoice->fresh());
    }

    /** The signed QR (or, without it, the IRN) as a PNG data URI for the PDF. */
    public function qrImage(Invoice $invoice): ?string
    {
        $text = $invoice->irn_signed_qr ?: $invoice->irn;
        if (! $text) {
            return null;
        }

        return (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'scale' => 3, 'outputBase64' => true, 'quietzoneSize' => 1])))->render($text);
    }

    private static function pin(string $address): ?int
    {
        return preg_match_all('/(?<!\d)(\d{6})(?!\d)/', $address, $m) ? (int) end($m[1]) : null;
    }
}
