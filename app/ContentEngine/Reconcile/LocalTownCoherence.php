<?php

namespace App\ContentEngine\Reconcile;

/**
 * Keeps a blog post's town references geographically coherent: a single post can't be relevant to
 * Montclair, NJ and Downingtown, PA at once, so from the towns it names we keep only the DOMINANT
 * (county, state) cluster and cap it. The rest are dropped — geo depth belongs on location pages, not
 * spread thin across one article.
 *
 * Dominant = the cluster the copy references most (by count), preferring a county-resolved cluster over
 * a state-only one on a tie, then the earliest-appearing. Cap defaults to `launchpad.local_town_cap` (4).
 */
final class LocalTownCoherence
{
    public const CAP = 4;

    /**
     * @param  list<array{key: string, display: string, county: ?string, state: ?string, pos: int}>  $matched
     * @return list<array{key: string, display: string, county: ?string, state: ?string, pos: int}>
     */
    public static function select(array $matched, ?int $cap = null): array
    {
        if ($matched === []) {
            return [];
        }
        $cap ??= (int) config('launchpad.local_town_cap', self::CAP);

        // Group by county+state (fall back to state-only when the county is unknown).
        $groups = [];
        foreach ($matched as $m) {
            $hasCounty = $m['county'] !== null && trim((string) $m['county']) !== '';
            $gk = $hasCounty ? 'c:'.$m['county'].'|'.($m['state'] ?? '') : 's:'.($m['state'] ?? '?');
            $groups[$gk] ??= ['rows' => [], 'county' => $hasCounty];
            $groups[$gk]['rows'][] = $m;
        }

        // Dominant cluster: most towns, then county-resolved over state-only, then earliest appearance.
        $best = null;
        foreach ($groups as $g) {
            if ($best === null || self::beats($g, $best)) {
                $best = $g;
            }
        }
        $rows = $best['rows'];
        usort($rows, fn (array $a, array $b): int => $a['pos'] <=> $b['pos']);

        return $cap > 0 ? array_slice($rows, 0, $cap) : $rows;
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, county: bool}  $g
     * @param  array{rows: list<array<string, mixed>>, county: bool}  $best
     */
    private static function beats(array $g, array $best): bool
    {
        $gc = count($g['rows']);
        $bc = count($best['rows']);
        if ($gc !== $bc) {
            return $gc > $bc;
        }
        if ($g['county'] !== $best['county']) {
            return $g['county']; // a county-resolved cluster wins a tie over a state-only one
        }

        return self::minPos($g['rows']) < self::minPos($best['rows']);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private static function minPos(array $rows): int
    {
        return (int) min(array_map(fn (array $r): int => (int) $r['pos'], $rows));
    }
}
