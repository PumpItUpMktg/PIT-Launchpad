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

    /**
     * Rewrite every phone number found in a run of VISIBLE PROSE into the one canonical {@see display}
     * format, so a number the drafter quoted verbatim (e.g. "+1 908-224-0550") reads the same as the
     * CTA/contact block ("+1 (908) 224-0550"). NAP is single-source everywhere the number appears —
     * structured OR in copy — and a repush re-runs this at compose time, healing already-published pages
     * with no regeneration.
     *
     * Deliberately conservative: it matches a US/NANP shape only (optional +1/1 country code, then a
     * 3-3-4 grouping with the usual space/dot/dash/paren separators), anchored so it can't bite a chunk
     * out of a longer alphanumeric run, and it only rewrites a token whose digits actually form a
     * 10-digit (or 11-with-leading-1) number. Anything else is returned untouched. Apply it ONLY to
     * drafted text — never to markup — so `tel:` hrefs (built separately from the resolved number) are
     * never rewritten. Idempotent: canonical input is left as-is.
     */
    public static function canonicalizeInText(?string $text): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        $pattern = '/(?<![\w+])(\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\w)/';

        return (string) preg_replace_callback($pattern, static function (array $m): string {
            $token = $m[0];
            $digits = preg_replace('/\D/', '', $token) ?? '';
            if (strlen($digits) === 10 || (strlen($digits) === 11 && $digits[0] === '1')) {
                return self::display($token) ?? $token;
            }

            return $token;
        }, $text);
    }
}
