<?php

namespace App\Integrations\DataForSeo;

use App\Enums\DataForSeoMode;
use App\Integrations\LocalGrid\GridMetrics;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\LocalGrid\LocalPackCompetitor;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Live DataForSEO LocalGridProvider. Supplies NORMALIZED geo-grid signals only —
 * §5 keeps computing local-lane beatability.
 *
 * The adapter does NOT define the grid: it derives an NxN point grid from the
 * §1 Market centre (lat/lng) at the configured step, queries SERP google/maps
 * per coordinate, locates the site's own listing per cell, and aggregates into
 * the GridMetrics contract (avgRank / pctTop3 / coverage + pack competitors).
 *
 * Mode-aware like the SerpProvider: live fetches every cell synchronously and
 * caches it; standard dispatches deduped maps tasks per cell and returns a
 * neutral (empty) grid until the ingest sweep has filled every cell — the next
 * refresh then aggregates real data. Per-cell results are cached so the grid is
 * never re-fetched inside the refresh cadence.
 */
class DataForSeoLocalGridProvider implements LocalGridProvider
{
    public const MAPS_POST = '/v3/serp/google/maps/task_post';

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly SerpTaskDispatcher $dispatcher,
        private readonly Cache $cache,
        private readonly DataForSeoMode $mode,
        private readonly string $language,
        private readonly int $gridSize,
        private readonly float $gridStep,
        private readonly int $cacheTtlHours,
    ) {}

    public function grid(string $query, string $marketId): GridMetrics
    {
        $market = Market::withoutGlobalScope(SiteScope::class)->with('site')->find($marketId);

        // No geo centre means no grid to build — neutral, empty (coverage 0).
        if ($market === null || $market->lat === null || $market->lng === null) {
            return new GridMetrics($query, avgRank: 0.0, pctTop3: 0.0, coverage: 0.0);
        }

        $ownDomain = $this->ownDomain($market);
        $points = $this->gridPoints((float) $market->lat, (float) $market->lng);

        /** @var list<list<array{rank: int|null, name: string, domain: string|null}>> $cells */
        $cells = [];
        $awaiting = false;

        foreach ($points as $coord) {
            $key = $this->mapsKey($coord, $query);
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                /** @var list<array{rank: int|null, name: string, domain: string|null}> $cached */
                $cells[] = $cached;

                continue;
            }

            if ($this->mode === DataForSeoMode::Live) {
                $items = $this->client->liveMaps($query, $coord, $this->language);
                $this->cache->put($key, $items, $this->ttl());
                $cells[] = $items;

                continue;
            }

            // Standard: dispatch a deduped maps task for this cell.
            $this->dispatcher->ensure('maps', $key, self::MAPS_POST, [
                'keyword' => $query,
                'location_coordinate' => $coord,
                'language_code' => $this->language,
            ]);
            $awaiting = true;
        }

        // Standard mode with cells still awaiting ingest: return a neutral grid
        // (no competitors → §5 reads an "open pack") until every cell lands.
        if ($awaiting) {
            return new GridMetrics($query, avgRank: 0.0, pctTop3: 0.0, coverage: 0.0);
        }

        return $this->aggregate($query, $cells, $ownDomain);
    }

    /**
     * Aggregate per-cell maps results into the normalized GridMetrics. avgRank
     * is averaged over the cells where the site's own listing appears; pctTop3
     * and coverage are over all cells. When the site appears in no cell, avgRank
     * is 0.0 and coverage 0.0 (read coverage to disambiguate "absent" from a
     * literal rank).
     *
     * @param  list<list<array{rank: int|null, name: string, domain: string|null}>>  $cells
     */
    private function aggregate(string $query, array $cells, ?string $ownDomain): GridMetrics
    {
        $total = count($cells);
        $found = 0;
        $top3 = 0;
        $rankSum = 0;

        /** @var array<string, LocalPackCompetitor> $competitors */
        $competitors = [];

        foreach ($cells as $items) {
            $ourRank = null;

            foreach ($items as $item) {
                $domain = $item['domain'];
                $name = $item['name'];

                if ($ownDomain !== null && $domain !== null && $this->sameDomain($domain, $ownDomain)) {
                    if ($ourRank === null && $item['rank'] !== null) {
                        $ourRank = (int) $item['rank'];
                    }

                    continue;
                }

                if ($name === '') {
                    continue;
                }

                $dedupe = strtolower(($domain ?? '').'|'.$name);
                $competitors[$dedupe] ??= new LocalPackCompetitor($name, $domain);
            }

            if ($ourRank !== null) {
                $found++;
                $rankSum += $ourRank;
                if ($ourRank <= 3) {
                    $top3++;
                }
            }
        }

        return new GridMetrics(
            query: $query,
            avgRank: $found > 0 ? $rankSum / $found : 0.0,
            pctTop3: $total > 0 ? $top3 / $total : 0.0,
            coverage: $total > 0 ? $found / $total : 0.0,
            packCompetitors: array_values($competitors),
        );
    }

    /**
     * Build the NxN grid of "lat,lng,zoom" coordinate strings centred on the
     * market, stepping `grid_step` degrees per cell. An odd grid_size keeps the
     * market centre as the middle cell.
     *
     * @return list<string>
     */
    private function gridPoints(float $lat, float $lng): array
    {
        $points = [];
        $half = (int) floor($this->gridSize / 2);

        for ($row = -$half; $row <= $half; $row++) {
            for ($col = -$half; $col <= $half; $col++) {
                $pLat = $lat + ($row * $this->gridStep);
                $pLng = $lng + ($col * $this->gridStep);
                // DataForSEO maps location_coordinate is "lat,lng,zoom".
                $points[] = sprintf('%.7f,%.7f,%d', $pLat, $pLng, 14);
            }
        }

        return $points;
    }

    private function ownDomain(Market $market): ?string
    {
        $url = $market->site?->domain_url;
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url(
            str_contains($url, '://') ? $url : 'https://'.$url,
            PHP_URL_HOST,
        );

        return is_string($host) ? $this->normalizeDomain($host) : $this->normalizeDomain($url);
    }

    private function sameDomain(string $a, string $b): bool
    {
        return $this->normalizeDomain($a) === $this->normalizeDomain($b);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    private function mapsKey(string $coordinate, string $query): string
    {
        return "dfs:maps:{$this->language}:{$coordinate}:".md5($query);
    }

    private function ttl(): int
    {
        return $this->cacheTtlHours * 3600;
    }
}
