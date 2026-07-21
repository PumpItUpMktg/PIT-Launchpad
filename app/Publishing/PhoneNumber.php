<?php

namespace App\Publishing;

/**
 * The single phone formatter — every rendered phone (chrome, hero/CTA buttons, conversion block,
 * schema) produces its `tel:` href and its human-readable display through here, so a number is never
 * a raw digit string in visible copy and the same input always formats the same way.
 */
final class PhoneNumber
{
    /** A `tel:` href in E.164-ish form (digits + a leading +), or null when there's no number. */
    public static function tel(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);

        return $digits !== '' ? 'tel:'.$digits : null;
    }

    /**
     * Human-readable display: "(877) 786-7834" for a US 10-digit number, "+1 (877) 786-7834" for an
     * 11-digit number with a leading country code, else the trimmed input verbatim (an
     * already-formatted or international number is left as the human entered it — never mangled).
     */
    public static function display(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $raw = trim($phone);
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('+1 (%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7, 4));
        }

        return $raw;
    }
}
