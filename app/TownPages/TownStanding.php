<?php

namespace App\TownPages;

use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;

/**
 * Where a town page stands TODAY for each priority keyword — its town-search rank in the latest finished
 * Town Rank scan — so a push never rewrites what is already ranking. Batched: one scans query and one
 * points query for a whole area's towns.
 */
final class TownStanding
{
    /** Page 1 of the town search: a keyword at or above this rank is "ranking" and its section is left alone. */
    public const PAGE1_MAX = 10;

    /**
     * @param  list<string>  $geoIds
     * @param  list<Keyword>  $keywords
     * @return array<string, array<string, array{rank: int|null, measured_at: string|null, scanned: bool}>> GEOID → keyword id → standing
     */
    public function forTowns(Site $site, array $geoIds, array $keywords): array
    {
        $geoIds = array_values(array_unique(array_filter($geoIds)));
        $keywordIds = PrioritySections::ids($keywords);
        $empty = ['rank' => null, 'measured_at' => null, 'scanned' => false];
        $out = [];
        foreach ($geoIds as $geoId) {
            $out[$geoId] = array_fill_keys($keywordIds, $empty);
        }
        if ($geoIds === [] || $keywordIds === []) {
            return $out;
        }

        // The latest finished town-search scan per keyword.
        $scanByKeyword = [];
        $scans = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('keyword_id', $keywordIds)->where('mode', TownRankScan::MODE_TOWN_QUERY)
            ->whereIn('status', ['complete', 'partial'])
            ->orderByDesc('scanned_at')
            ->get(['id', 'keyword_id']);
        foreach ($scans as $scan) {
            $scanByKeyword[(string) $scan->keyword_id] ??= (string) $scan->id;
        }
        if ($scanByKeyword === []) {
            return $out;
        }
        $keywordByScan = array_flip($scanByKeyword);

        $areaGeo = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereIn('geo_id', $geoIds)
            ->pluck('geo_id', 'id');
        $points = TownRankPoint::withoutGlobalScope(SiteScope::class)
            ->whereIn('scan_id', array_values($scanByKeyword))
            ->where(fn ($q) => $q->whereIn('coverage_area_id', $areaGeo->keys()->all())->orWhereIn('geo_id', $geoIds))
            ->whereNotNull('collected_at')
            ->get(['scan_id', 'coverage_area_id', 'geo_id', 'rank', 'collected_at']);
        foreach ($points as $point) {
            $geoId = trim((string) $point->geo_id) !== '' ? (string) $point->geo_id : (string) ($areaGeo[(string) $point->coverage_area_id] ?? '');
            $keywordId = $keywordByScan[(string) $point->scan_id] ?? null;
            if ($geoId === '' || $keywordId === null || ! isset($out[$geoId])) {
                continue;
            }
            $out[$geoId][$keywordId] = [
                'rank' => $point->rank !== null ? (int) $point->rank : null,
                'measured_at' => $point->collected_at?->toIso8601String(),
                'scanned' => true,
            ];
        }

        return $out;
    }

    /** @param  list<Keyword>  $keywords @return array<string, array{rank: int|null, measured_at: string|null, scanned: bool}> */
    public function forTown(Site $site, string $geoId, array $keywords): array
    {
        return $this->forTowns($site, [$geoId], $keywords)[$geoId] ?? [];
    }

    public static function ranking(?int $rank): bool
    {
        return $rank !== null && $rank >= 1 && $rank <= self::PAGE1_MAX;
    }
}
