<?php

namespace App\Services;

use App\Models\Matter;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class MatterReference
{
    public static function parse(?string $reference): ?array
    {
        if (! preg_match('/^([PDT])(\d{2})(\d{4})(\d{2})([A-Z]{2})$/', strtoupper(trim($reference ?? '')), $parts)) {
            return null;
        }

        return ['type' => ['P' => 'Patent', 'D' => 'Design', 'T' => 'Trademark'][$parts[1]], 'year' => 2000 + (int) $parts[2], 'client_ref' => $parts[3], 'matter_no' => $parts[4], 'country_code' => $parts[5]];
    }

    public static function resolve(string $reference, ?User $actor = null, ?int $clientId = null): Matter
    {
        $reference = strtoupper(trim($reference));
        $query = Matter::query()->when($clientId, fn ($q) => $q->where('client_id', $clientId));
        if ($actor?->hasRole('Paralegal')) {
            $query->where('paralegal_id', $actor->paralegal?->id ?? 0);
        }
        if (preg_match('/^\d{4}$/', $reference)) {
            $query->where('ref_no', $reference);
        } elseif (self::parse($reference)) {
            $query->where('our_ref', $reference);
        } else {
            throw ValidationException::withMessages(['ref_no' => 'Use the full matter reference, for example P25124809KR.']);
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['ref_no' => $matches->count() > 1 ? 'This client has multiple matters. Enter the full matter reference, for example P25124809KR.' : 'Matter reference was not found or is not assigned to you.']);
        }

        return $matches->first();
    }
}
