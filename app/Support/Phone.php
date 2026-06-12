<?php

namespace App\Support;

/**
 * Pragmatic phone normalization for the onboarding location form: store E.164,
 * display formatted. Not a full libphonenumber — US is the default region (the
 * home-services tenants are US), an explicit `+` is treated as already
 * international, and anything unrecognized is kept as best-effort digits rather
 * than rejected (phone is never a gate).
 */
final class Phone
{
    /**
     * Normalize a raw phone string to E.164 (+<country><number>), or null when
     * there is nothing to store.
     */
    public static function toE164(string $raw, string $region = 'US'): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $international = str_starts_with($trimmed, '+');
        $digits = (string) preg_replace('/\D/', '', $trimmed);
        if ($digits === '') {
            return null;
        }

        if ($international) {
            return '+'.$digits;
        }

        if ($region === 'US') {
            if (strlen($digits) === 10) {
                return '+1'.$digits;
            }
            if (strlen($digits) === 11 && $digits[0] === '1') {
                return '+'.$digits;
            }
        }

        return '+'.$digits;
    }

    /**
     * Human display: a US E.164 renders as (XXX) XXX-XXXX; anything else is
     * returned unchanged.
     */
    public static function format(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }

        if (preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m) === 1) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return $e164;
    }
}
