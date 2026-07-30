<?php

namespace App\Locations;

use App\Models\CoverageArea;

/**
 * Repairs the numbered-list parse artifact in a coverage-town name — the "6, Havre de Grace" /
 * "2, Halls Cross Roads" shape, where a leading list index/count column stuck to the town name at
 * ingest (a pasted or ranked list). {@see ServedTowns::isValidEntry()} already REJECTS
 * this shape at the served-towns input; this is the repair for names that predate that guard, and the
 * write-time normalizer on {@see CoverageArea} so it can never re-enter through any path.
 *
 * Targets a leading integer followed by a comma or period (the list-marker forms) only — never a bare
 * "29 Palms" style name (no separator), so a legitimately number-led locality is left untouched.
 */
final class CoverageName
{
    public static function clean(string $name): string
    {
        $out = trim($name);

        // Strip a leading "<digits><comma|period><space>" list marker, repeatedly (e.g. "6, " → "").
        do {
            $before = $out;
            $out = trim((string) preg_replace('/^\d+\s*[.,]\s*/', '', $out));
        } while ($out !== $before && $out !== '');

        // Degrade safely: if stripping emptied it (a bare "6,"), keep the original trimmed value.
        return $out !== '' ? $out : trim($name);
    }

    /** True when the name carries the artifact (so a sweep can target only the rows that need it). */
    public static function isDirty(string $name): bool
    {
        return self::clean($name) !== trim($name);
    }
}
