<?php

namespace App\Services;

final class Money
{
    public static function round(string $n): string
    {
        return bcadd($n, bccomp($n, '0', 8) >= 0 ? '0.005' : '-0.005', 2);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, 8));
    }

    public static function format(string $amount, string $currency): string
    {
        $n = new \NumberFormatter($currency === 'INR' ? 'en_IN' : 'en_US', \NumberFormatter::CURRENCY);

        return $n->formatCurrency((float) $amount, $currency).' '.$currency;
    }

    public static function words(string $amount, string $currency): string
    {
        $parts = explode('.', self::round($amount));
        $f = new \NumberFormatter($currency === 'INR' ? 'en_IN' : 'en', \NumberFormatter::SPELLOUT);
        $s = ucwords(str_replace(['-', ' and '], [' ', ' '], $f->format((int) $parts[0])));

        return ($currency === 'INR' ? 'Rupees' : ($currency === 'EUR' ? 'EUR €' : 'USD $')).' '.$s.((int) $parts[1] ? ' and '.$parts[1].'/100' : '').' Only';
    }
}
