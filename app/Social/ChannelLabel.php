<?php

namespace App\Social;

/**
 * The short names the Shares board shows for a GBP channel. Pure string work, derived — never typed in:
 * the tenant's brand abbreviated to its initials ("Sump Pump Gurus" → "SPG"), the office named by its town
 * ("Trooper office" → "Trooper"), Google's own title kept when it is another brand's listing or when it
 * names a different town than the office it landed on (so a wrong match is visible at a glance).
 */
final class ChannelLabel
{
    /** Words an office name carries that are not its town. */
    private const GENERIC = ['office', 'offices', 'branch', 'hq', 'headquarters', 'location', 'shop', 'store', 'the', 'and', 'of', 'main', 'warehouse', 'depot'];

    /** "Sump Pump Gurus" → "SPG"; a one-word brand stays itself. */
    public static function brandAbbreviation(string $brand): string
    {
        $words = preg_split('/\s+/', trim($brand)) ?: [];
        $words = array_values(array_filter($words, fn (string $w): bool => preg_match('/[A-Za-z0-9]/', $w) === 1));
        if (count($words) < 2) {
            return trim($brand);
        }
        $initials = '';
        foreach ($words as $w) {
            $initials .= mb_strtoupper(mb_substr((string) preg_replace('/^[^A-Za-z0-9]+/', '', $w), 0, 1));
        }

        return $initials;
    }

    /** "Trooper office" → "Trooper"; "Clifton corporate office" → "Clifton corporate"; a name of only generic words stays whole. */
    public static function officeTown(string $office): string
    {
        $words = preg_split('/\s+/', trim($office)) ?: [];
        $kept = array_values(array_filter($words, fn (string $w): bool => ! in_array(strtolower(trim($w, " \t,.-–—")), self::GENERIC, true)));

        return $kept === [] ? trim($office) : implode(' ', $kept);
    }

    /** Google's title starts with the tenant's brand (case-insensitive, punctuation-insensitive). */
    public static function isBrand(string $title, string $brand): bool
    {
        $t = self::fold($title);
        $b = self::fold($brand);

        return $b !== '' && ($t === $b || str_starts_with($t, $b.' '));
    }

    /** The town Google's title names after the brand ("Sump Pump Gurus | Trooper" → "Trooper"), or null. */
    public static function listingTown(string $title, string $brand): ?string
    {
        if (! self::isBrand($title, $brand)) {
            return null;
        }
        $rest = trim(mb_substr(trim($title), mb_strlen(trim($brand))));
        $rest = trim((string) preg_replace('/^[\s|:–—-]+/u', '', $rest));

        return $rest !== '' ? $rest : null;
    }

    /**
     * The pill text for a GBP channel.
     *   matched, same town (or none in the title) → "SPG · Trooper"
     *   matched, title names another town        → "SPG | Spring City → Phoenixville"
     *   unmatched, own brand                       → "SPG | Trooper" / "SPG"
     *   another brand's listing                    → Google's title (+ " → office" when matched)
     */
    public static function gbpPill(string $title, string $brand, ?string $office): string
    {
        $title = trim($title);
        if (! self::isBrand($title, $brand)) {
            return $title.($office !== null ? ' → '.self::officeTown($office) : '');
        }
        $abbr = self::brandAbbreviation($brand);
        $town = self::listingTown($title, $brand);
        if ($office === null) {
            return $abbr.($town !== null ? ' | '.$town : '');
        }
        $officeTown = self::officeTown($office);
        if ($town === null || self::sameTown($town, $officeTown)) {
            return $abbr.' · '.$officeTown;
        }

        return $abbr.' | '.$town.' → '.$officeTown;
    }

    private static function sameTown(string $a, string $b): bool
    {
        $a = self::fold($a);
        $b = self::fold($b);

        return $a === $b || str_contains(' '.$b.' ', ' '.$a.' ') || str_contains(' '.$a.' ', ' '.$b.' ');
    }

    private static function fold(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $value))));
    }
}
