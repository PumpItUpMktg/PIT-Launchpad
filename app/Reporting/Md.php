<?php

namespace App\Reporting;

/**
 * Tiny markdown helpers for the tenant-state report ({@see TenantReport}) — traffic-light glyphs,
 * counts-first list capping, and present/missing markers. Kept dependency-free and deterministic so two
 * consecutive reports over unchanged state diff cleanly.
 */
final class Md
{
    public const OK = '✅';

    public const WARN = '⚠️';

    public const BAD = '❌';

    /** A present/absent tick for a boolean field. */
    public static function tick(bool $present): string
    {
        return $present ? self::OK : self::BAD;
    }

    /** ✅ when zero, ⚠️/❌ when a "must be 0" count is violated. */
    public static function zeroGate(int $count, bool $hard = false): string
    {
        return $count === 0 ? self::OK : ($hard ? self::BAD : self::WARN);
    }

    /**
     * A capped list block: the first $cap items (already sorted by the caller) then "+ n more".
     * Counts-first is the caller's job; this only bounds the printed lines.
     *
     * @param  list<string>  $items
     * @return list<string> markdown lines (each already prefixed as desired by the caller)
     */
    public static function capped(array $items, int $cap = 10): array
    {
        $shown = array_slice($items, 0, $cap);
        $extra = count($items) - count($shown);
        if ($extra > 0) {
            $shown[] = "_+ {$extra} more_";
        }

        return $shown;
    }

    /** "n/total" with a percentage — the fill-rate idiom used across Intake. */
    public static function ratio(int $n, int $total): string
    {
        if ($total === 0) {
            return '0/0';
        }

        return $n.'/'.$total.' ('.(int) round($n / $total * 100).'%)';
    }

    /** A value or an em-dash when empty — never a blank cell. */
    public static function orDash(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '—' : $value;
    }
}
