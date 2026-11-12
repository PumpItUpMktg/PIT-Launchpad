<?php

namespace App\TownRank;

/**
 * How far one Business Profile reaches, from a coverage scan's per-town pack ranks. The county-wide share
 * held one GBP responsible for every town in the county; the pack favours proximity, so the honest
 * measures are by distance from the listing's own address:
 *
 *   bands        — pack presence per distance band (0–5 / 5–10 / 15+ mi), population-weighted;
 *   reach_miles  — walking outward, the farthest town at which the GBP is still in the pack for at least
 *                  half the population measured so far (null when it never is);
 *   score        — 0–100: Σ pop · w(d) · credit(rank) / Σ pop · w(d), with w(d) = 1 / (1 + (d/d0)²) so a town
 *                  3 mi out counts fully and one 20 mi out barely, and credit 1 for the pack (rank ≤ 3),
 *                  0.5 for 4–7, 0.25 for 8–10, 0 beyond or absent;
 *   addressable  — the share of the area's population inside the reach radius: what one listing can win;
 *   competitors  — each lost town (measured, not in the pack) split by whether the pack's winner sits
 *                  closer to that town than we do (geography) or farther (actionable), given the winners'
 *                  coordinates; the rest are `unknown` until Places resolves them.
 *
 * Pure: no queries. A town with no population counts as 1 so it is never silently dropped.
 */
final class GbpReach
{
    /**
     * @param  list<array{id: string, name: string, population: int, lat: float, lng: float, rank: int|null, measured: bool, competitors: list<array{name: string, place_id: string|null, rank: int|null}>}>  $towns
     * @param  array<string, array{lat: float, lng: float, name: string|null}>  $competitorCoords
     * @param  list<int>  $bandEdges
     * @return array{measured: int, bands: list<array{label: string, from: int, to: int|null, towns: int, in_pack: int, share: int|null, population: int}>, reach_miles: float|null, score: int|null, addressable: int|null, decay_miles: float, competitors: array{lost: int, closer: int, farther: int, unknown: int, farther_towns: list<array{town: string, by: string, their_miles: float, our_miles: float}>, closer_towns: list<array{town: string, by: string, their_miles: float, our_miles: float}>}}
     */
    public static function compute(array $towns, float $centerLat, float $centerLng, float $decayMiles, array $competitorCoords = [], array $bandEdges = [5, 10, 15]): array
    {
        $decayMiles = max(0.5, $decayMiles);
        $rows = [];
        foreach ($towns as $t) {
            $rows[] = [...$t, 'distance' => self::miles($centerLat, $centerLng, (float) $t['lat'], (float) $t['lng']), 'weight' => max(1, (int) $t['population'])];
        }
        usort($rows, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $measured = array_values(array_filter($rows, fn (array $r): bool => $r['measured']));

        // Bands.
        $edges = array_values(array_unique(array_map('intval', $bandEdges)));
        sort($edges);
        $bands = [];
        $from = 0;
        foreach ([...$edges, null] as $to) {
            $inBand = array_values(array_filter($measured, fn (array $r): bool => $r['distance'] >= $from && ($to === null || $r['distance'] < $to)));
            $pop = array_sum(array_column($inBand, 'weight'));
            $inPackPop = array_sum(array_map(fn (array $r): int => self::inPack($r['rank']) ? $r['weight'] : 0, $inBand));
            $bands[] = [
                'label' => $to === null ? "{$from}+ mi" : "{$from}–{$to} mi",
                'from' => $from,
                'to' => $to,
                'towns' => count($inBand),
                'in_pack' => count(array_filter($inBand, fn (array $r): bool => self::inPack($r['rank']))),
                'share' => $pop > 0 ? (int) round($inPackPop / $pop * 100) : null,
                'population' => (int) $pop,
            ];
            if ($to !== null) {
                $from = $to;
            }
        }

        // Reach: cumulative outward, population-weighted; the farthest town where the running share is ≥ ½.
        $reach = null;
        $cumPop = 0;
        $cumIn = 0;
        foreach ($measured as $r) {
            $cumPop += $r['weight'];
            $cumIn += self::inPack($r['rank']) ? $r['weight'] : 0;
            if ($cumIn / $cumPop >= 0.5 && self::inPack($r['rank'])) {
                $reach = round($r['distance'], 1);
            }
        }

        // Proximity-weighted score.
        $num = 0.0;
        $den = 0.0;
        foreach ($measured as $r) {
            $w = $r['weight'] / (1 + ($r['distance'] / $decayMiles) ** 2);
            $den += $w;
            $num += $w * self::credit($r['rank']);
        }
        $score = $den > 0 ? (int) round($num / $den * 100) : null;

        // Addressable: the area's people inside the reach radius (every town, measured or not).
        $allPop = array_sum(array_column($rows, 'weight'));
        $addressable = $reach === null || $allPop <= 0 ? null
            : (int) round(array_sum(array_map(fn (array $r): int => $r['distance'] <= $reach ? $r['weight'] : 0, $rows)) / $allPop * 100);

        // Lost towns, split by the winner's distance.
        $lost = ['lost' => 0, 'closer' => 0, 'farther' => 0, 'unknown' => 0, 'farther_towns' => [], 'closer_towns' => []];
        foreach (array_reverse($measured) as $r) {   // largest-first order is set below; iterate all
            if (self::inPack($r['rank'])) {
                continue;
            }
            $lost['lost']++;
            $winner = null;
            foreach ($r['competitors'] as $c) {
                if (($c['rank'] ?? null) !== null && ($winner === null || $c['rank'] < $winner['rank'])) {
                    $winner = $c;
                }
            }
            $pid = $winner['place_id'] ?? null;
            if ($winner === null || ! is_string($pid) || ! isset($competitorCoords[$pid])) {
                $lost['unknown']++;

                continue;
            }
            $theirs = round(self::miles((float) $r['lat'], (float) $r['lng'], $competitorCoords[$pid]['lat'], $competitorCoords[$pid]['lng']), 1);
            $ours = round($r['distance'], 1);
            $entry = ['town' => (string) $r['name'], 'by' => (string) ($competitorCoords[$pid]['name'] ?? $winner['name']), 'their_miles' => $theirs, 'our_miles' => $ours, 'population' => $r['weight']];
            if ($theirs < $ours) {
                $lost['closer']++;
                $lost['closer_towns'][] = $entry;
            } else {
                $lost['farther']++;
                $lost['farther_towns'][] = $entry;
            }
        }
        foreach (['farther_towns', 'closer_towns'] as $k) {
            usort($lost[$k], fn (array $a, array $b): int => $b['population'] <=> $a['population']);
            $lost[$k] = array_map(fn (array $e): array => ['town' => $e['town'], 'by' => $e['by'], 'their_miles' => $e['their_miles'], 'our_miles' => $e['our_miles']], array_slice($lost[$k], 0, 8));
        }

        return [
            'measured' => count($measured),
            'bands' => $bands,
            'reach_miles' => $reach,
            'score' => $score,
            'addressable' => $addressable,
            'decay_miles' => $decayMiles,
            'competitors' => $lost,
        ];
    }

    /** The place ids of every pack winner across the towns — what the resolver needs to know. @param list<array{competitors: list<array{place_id: string|null, rank: int|null}>}> $towns @return list<string> */
    public static function competitorIds(array $towns): array
    {
        $ids = [];
        foreach ($towns as $t) {
            foreach ($t['competitors'] as $c) {
                if (is_string($c['place_id'] ?? null) && $c['place_id'] !== '') {
                    $ids[$c['place_id']] = true;
                }
            }
        }

        return array_keys($ids);
    }

    public static function inPack(?int $rank): bool
    {
        return $rank !== null && $rank <= 3;
    }

    private static function credit(?int $rank): float
    {
        return match (true) {
            $rank === null => 0.0,
            $rank <= 3 => 1.0,
            $rank <= 7 => 0.5,
            $rank <= 10 => 0.25,
            default => 0.0,
        };
    }

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
