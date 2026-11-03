<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Validation\ValidationException;

class InvoiceFormat
{
    public const LABELS = ['combined' => 'Combined - Official + Professional', 'detailed' => 'Detailed - fee breakdown', 'india_gst' => 'India - Official + Professional + GST'];

    public const FEES = ['Official Fees', 'Professional Fees', 'Translation', 'Disbursement', 'Official + Professional Fees'];

    public static function resolve(?string $format, Client $client): string
    {
        $format = $format ?: ($client->client_type === 'Indian' ? 'india_gst' : 'detailed');
        if (! isset(self::LABELS[$format])) {
            throw ValidationException::withMessages(['invoice_layout' => 'Select a valid invoice format.']);
        }
        if (($client->client_type === 'Indian') !== ($format === 'india_gst')) {
            throw ValidationException::withMessages(['invoice_layout' => 'Indian clients use the India GST format; foreign clients use Combined or Detailed.']);
        }

        return $format;
    }

    public static function validateRows(string $format, array $rows): void
    {
        foreach ($rows as $row) {
            $fee = $row['fee_type'] ?? '';
            if (! in_array($fee, self::FEES, true)) {
                throw ValidationException::withMessages(['items' => 'Select a valid fee type.']);
            }
            if ($format === 'combined' && $fee === 'Translation') {
                throw ValidationException::withMessages(['items' => 'Use Detailed format for a separate Translation charge.']);
            }
            if ($format !== 'combined' && $fee === 'Official + Professional Fees') {
                throw ValidationException::withMessages(['items' => 'Enter Official Fees and Professional Fees separately for a breakdown or India GST calculation.']);
            }
        }
    }
}
