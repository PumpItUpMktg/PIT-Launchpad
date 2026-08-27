<?php

namespace App\GeoGrid;

use RuntimeException;

/**
 * Parses a Local Falcon point export into the normalized point set {@see GeoGridCalibration} compares against.
 * Local Falcon's native CSV/JSON exports vary, so this accepts a simple, documented shape: a header row plus
 * one row per grid point carrying at least lat/lng and a rank. The operator maps their export's columns to
 * these once; the parser tolerates the common ways "not found" is written.
 *
 *   Required columns (case-insensitive, common aliases accepted):
 *     lat  | latitude
 *     lng  | lon | long | longitude
 *     rank | ranking | position    ("", "-", "0", "20+", ">20", "X", "not found" → not found within depth)
 *   Optional: row, col (ignored for matching — alignment is by lat/lng — but accepted).
 *
 * A rank that parses to an integer in 1..depth_cap is a real rank; anything else is "not found" (null).
 */
final class LocalFalconGrid
{
    /**
     * @return list<array{lat: float, lng: float, rank: ?int, row: ?int, col: ?int}>
     */
    public static function fromCsv(string $path, int $depthCap): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Local Falcon export not readable: {$path}");
        }

        $rows = array_map('str_getcsv', array_filter(array_map('trim', (array) file($path)), fn ($l): bool => $l !== ''));
        if (count($rows) < 2) {
            throw new RuntimeException('Local Falcon export has no data rows.');
        }

        $header = array_map(fn ($h): string => mb_strtolower(trim((string) $h)), array_shift($rows));
        $latI = self::col($header, ['lat', 'latitude']);
        $lngI = self::col($header, ['lng', 'lon', 'long', 'longitude']);
        $rankI = self::col($header, ['rank', 'ranking', 'position', 'rank_absolute']);
        $rowI = self::col($header, ['row'], required: false);
        $colI = self::col($header, ['col', 'column'], required: false);

        $out = [];
        foreach ($rows as $r) {
            if (! isset($r[$latI], $r[$lngI])) {
                continue;
            }
            $out[] = [
                'lat' => (float) $r[$latI],
                'lng' => (float) $r[$lngI],
                'rank' => self::parseRank($r[$rankI] ?? null, $depthCap),
                'row' => $rowI !== null && isset($r[$rowI]) && $r[$rowI] !== '' ? (int) $r[$rowI] : null,
                'col' => $colI !== null && isset($r[$colI]) && $r[$colI] !== '' ? (int) $r[$colI] : null,
            ];
        }

        if ($out === []) {
            throw new RuntimeException('Local Falcon export parsed to zero points — check the column headers.');
        }

        return $out;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $aliases
     */
    private static function col(array $header, array $aliases, bool $required = true): ?int
    {
        foreach ($aliases as $alias) {
            $i = array_search($alias, $header, true);
            if ($i !== false) {
                return (int) $i;
            }
        }
        if ($required) {
            throw new RuntimeException('Local Falcon export missing a required column (one of: '.implode(', ', $aliases).').');
        }

        return null;
    }

    /** An integer rank in 1..depthCap, else null (not found within depth). */
    private static function parseRank(mixed $raw, int $depthCap): ?int
    {
        $s = mb_strtolower(trim((string) $raw));
        if ($s === '' || ! preg_match('/^\d+$/', $s)) {
            return null;   // "-", "20+", ">20", "x", "not found", blank → not found
        }
        $n = (int) $s;

        return $n >= 1 && $n <= $depthCap ? $n : null;
    }
}
