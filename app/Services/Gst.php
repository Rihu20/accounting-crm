<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Setting;

/** Place-of-supply helpers shared by invoices and credit / debit notes. */
final class Gst
{
    public const STATE_CODES = ['Jammu and Kashmir' => '01', 'Himachal Pradesh' => '02', 'Punjab' => '03', 'Chandigarh' => '04', 'Uttarakhand' => '05', 'Haryana' => '06', 'Delhi' => '07', 'Rajasthan' => '08', 'Uttar Pradesh' => '09', 'Bihar' => '10', 'Sikkim' => '11', 'Arunachal Pradesh' => '12', 'Nagaland' => '13', 'Manipur' => '14', 'Mizoram' => '15', 'Tripura' => '16', 'Meghalaya' => '17', 'Assam' => '18', 'West Bengal' => '19', 'Jharkhand' => '20', 'Odisha' => '21', 'Chhattisgarh' => '22', 'Madhya Pradesh' => '23', 'Gujarat' => '24', 'Dadra and Nagar Haveli and Daman and Diu' => '26', 'Maharashtra' => '27', 'Karnataka' => '29', 'Goa' => '30', 'Lakshadweep' => '31', 'Kerala' => '32', 'Tamil Nadu' => '33', 'Puducherry' => '34', 'Andaman and Nicobar Islands' => '35', 'Telangana' => '36', 'Andhra Pradesh' => '37', 'Ladakh' => '38'];

    public static function stateCode(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }
        if (filled($client->state_code)) {
            return str_pad((string) $client->state_code, 2, '0', STR_PAD_LEFT);
        }
        if (filled($client->gstin) && preg_match('/^(\d{2})/', $client->gstin, $m)) {
            return $m[1];
        }
        foreach (self::STATE_CODES as $state => $code) {
            if (strcasecmp(trim((string) $client->state), $state) === 0) {
                return $code;
            }
        }

        return null;
    }

    public static function companyStateCode(): string
    {
        $gstin = (string) Setting::get('company_gstin', '09');

        return preg_match('/^(\d{2})/', $gstin, $m) ? $m[1] : (self::STATE_CODES[Setting::get('company_state', 'Uttar Pradesh')] ?? '09');
    }

    public static function isExport(?Client $client): bool
    {
        return $client?->client_type !== 'Indian';
    }

    /** Same state as our GSTIN → CGST + SGST, otherwise IGST. */
    public static function intraState(?Client $client): bool
    {
        $code = self::stateCode($client);

        return ! self::isExport($client) && $code !== null && $code === self::companyStateCode();
    }

    public static function placeOfSupply(?Client $client): string
    {
        if (self::isExport($client)) {
            return 'Outside India – '.($client?->country ?: 'Export of services');
        }
        $code = self::stateCode($client);

        return trim(($client?->state ?: array_search($code, self::STATE_CODES, true) ?: '—').($code ? ' ('.$code.')' : ''));
    }

    public static function lutLine(): string
    {
        $arn = trim((string) Setting::get('lut_arn', ''));

        return 'Supply meant for export of services under LUT'.($arn !== '' ? ' (ARN '.$arn.')' : '').' without payment of IGST.';
    }
}
