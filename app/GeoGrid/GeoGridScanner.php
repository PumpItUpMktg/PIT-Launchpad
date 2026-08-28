<?php

namespace App\GeoGrid;

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scans one (location × keyword) as a geo grid via DataForSEO Google-Maps SERP, standard queue (task_post →
 * tasks_ready → task_get — NOT live/advanced; at grid_size² requests the price difference compounds). Ranks
 * are matched by place_id / CID, never business name (name matching fails silently at points where you
 * actually rank). Persists the {@see GeoGridScan} header + one {@see GeoGridPoint} per cell (rank null = the
 * business didn't appear within depth_cap, or the task never became ready). Aggregates are left null — PR 4
 * derives them from the points.
 *
 * zoom + depth + device are held constant from config (calibration constants). The caller (scan command)
 * owns the hard per-RUN request ceiling; this scans a single grid.
 *
 * Two point sources share the same post → match-by-place_id → persist machinery: GRID mode ({@see scan()},
 * an abstract square lattice, for Local Falcon parity / falloff) and COVERAGE mode ({@see scanCoverage()}, the
 * location's actual served towns via {@see CoverageGrid}, so each point is a real town we target). The scan
 * header's `mode` records which.
 */
final class GeoGridScanner
{
    private const MAPS_POST = '/v3/serp/google/maps/task_post';

    private const MAPS_READY = '/v3/serp/google/maps/tasks_ready';

    private const MAPS_GET = '/v3/serp/google/maps/task_get/advanced';

    /** DataForSEO accepts at most 100 tasks per task_post; a whole-county coverage scan exceeds that. */
    private const MAX_TASKS_PER_POST = 100;

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly GeoGridGeometry $geometry,
        private readonly CoverageGrid $coverage,
    ) {}

    /** GRID mode: scan an abstract square lattice around the location's GBP centre. */
    public function scan(Location $location, Keyword $keyword, ?float $spacingMilesOverride = null): GeoGridScan
    {
        $gridSize = max(1, (int) config('launchpad.geo_grid.grid_size', 7));
        $spacing = $spacingMilesOverride ?? $location->gridSpacingMiles();
        $centerLat = (float) $location->lat;
        $centerLng = (float) $location->lng;

        $points = $this->geometry->points($centerLat, $centerLng, $gridSize, $spacing);

        return $this->runScan($location, $keyword, $points, [
            'mode' => 'grid',
            'grid_size' => $gridSize,
            'spacing_miles' => $spacing,
            'center_lat' => $centerLat,
            'center_lng' => $centerLng,
        ]);
    }

    /**
     * COVERAGE mode: scan the location's served TOWNS (each municipality's centroid), so rank is keyed to a
     * real town we target. Point count = town count (variable per location, not grid_size²).
     */
    public function scanCoverage(Location $location, Keyword $keyword): GeoGridScan
    {
        $points = [];
        foreach ($this->coverage->pointsFor($location) as $i => $town) {
            $points[] = [
                // row/col are a synthetic linear index so the (scan_id,row,col) unique key holds; the town's
                // real identity is coverage_area_id.
                'row' => 0,
                'col' => $i,
                'lat' => $town['lat'],
                'lng' => $town['lng'],
                'coverage_area_id' => $town['coverage_area_id'],
                'label' => $town['label'],
            ];
        }

        return $this->runScan($location, $keyword, $points, [
            'mode' => 'coverage',
            'grid_size' => count($points),   // point count — towns, not a lattice dimension
            'spacing_miles' => 0,            // n/a: towns aren't uniformly spaced
            'center_lat' => (float) $location->lat,
            'center_lng' => (float) $location->lng,
        ]);
    }

    /**
     * The shared scan machinery: post one task per point, poll to completion, match the business by
     * place_id/CID, and persist the {@see GeoGridScan} header + one {@see GeoGridPoint} per point (carrying
     * whatever identity the point has — grid row/col or a coverage_area_id/label).
     *
     * @param  list<array{row: int, col: int, lat: float, lng: float, coverage_area_id?: string, label?: string}>  $points
     * @param  array{mode: string, grid_size: int, spacing_miles: float, center_lat: float, center_lng: float}  $header
     */
    private function runScan(Location $location, Keyword $keyword, array $points, array $header): GeoGridScan
    {
        $zoom = (int) config('launchpad.geo_grid.zoom', 13);
        $depthCap = (int) config('launchpad.geo_grid.depth_cap', 20);

        $taskIds = $this->postTasks($points, $keyword);

        // task id → point index (only the ids we got back, positionally).
        $idToIndex = [];
        foreach ($taskIds as $i => $taskId) {
            $idToIndex[$taskId] = $i;
        }

        $placeId = trim((string) $location->place_id);
        $cid = $this->cidFromGbpUrl((string) $location->gbp_url);

        // Poll standard-queue tasks until ours are ready (bounded), collecting each result.
        $results = $this->collect($idToIndex, $placeId, $cid);

        return DB::transaction(function () use ($location, $keyword, $points, $taskIds, $idToIndex, $results, $header, $zoom, $depthCap): GeoGridScan {
            $scan = GeoGridScan::create([
                'site_id' => $location->site_id,
                'location_id' => $location->id,
                'keyword_id' => $keyword->id,
                'provider' => 'dataforseo',
                'provider_scan_id' => $taskIds[0] ?? null,
                'mode' => $header['mode'],
                'grid_size' => $header['grid_size'],
                'spacing_miles' => $header['spacing_miles'],
                'center_lat' => $header['center_lat'],
                'center_lng' => $header['center_lng'],
                'zoom' => $zoom,
                'depth_cap' => $depthCap,
                'status' => count($results) >= count($points) ? 'complete' : 'partial',
                'scanned_at' => Carbon::now(),
            ]);

            $indexToTaskId = array_flip($idToIndex);
            foreach ($points as $i => $p) {
                $found = $results[$i] ?? null;
                GeoGridPoint::create([
                    'site_id' => $location->site_id,
                    'scan_id' => $scan->id,
                    'row' => $p['row'],
                    'col' => $p['col'],
                    'lat' => $p['lat'],
                    'lng' => $p['lng'],
                    'rank' => $found['rank'] ?? null,
                    'competitors' => $found['competitors'] ?? null,
                    'provider_task_id' => $indexToTaskId[$i] ?? null,
                    'coverage_area_id' => $p['coverage_area_id'] ?? null,
                    'label' => $p['label'] ?? null,
                ]);
            }

            return $scan;
        });
    }

    /**
     * COVERAGE mode, ASYNC: post one DataForSEO task per served town and persist a PENDING scan with a point
     * per town (rank + competitors filled in later by {@see collectPending}, driven by the IngestCoverageScans
     * sweep). A whole-county scan is 100+ rate-limited task_get calls — far past a single job's timeout — so
     * posting (fast) and collecting (slow, batched) are split. The synchronous {@see scanCoverage()} stays for
     * the CLI + calibration, where blocking is fine.
     */
    public function postCoverageScan(Location $location, Keyword $keyword): GeoGridScan
    {
        $points = [];
        foreach ($this->coverage->pointsFor($location) as $i => $town) {
            $points[] = [
                'row' => 0,
                'col' => $i,
                'lat' => $town['lat'],
                'lng' => $town['lng'],
                'coverage_area_id' => $town['coverage_area_id'],
                'label' => $town['label'],
            ];
        }

        $zoom = (int) config('launchpad.geo_grid.zoom', 13);
        $depthCap = (int) config('launchpad.geo_grid.depth_cap', 20);
        $taskIds = $this->postTasks($points, $keyword);

        return DB::transaction(function () use ($location, $keyword, $points, $taskIds, $zoom, $depthCap): GeoGridScan {
            $scan = GeoGridScan::create([
                'site_id' => $location->site_id,
                'location_id' => $location->id,
                'keyword_id' => $keyword->id,
                'provider' => 'dataforseo',
                'provider_scan_id' => $taskIds[0] ?? null,
                'mode' => 'coverage',
                'grid_size' => count($points),   // point count — towns, not a lattice dimension
                'spacing_miles' => 0,
                'center_lat' => (float) $location->lat,
                'center_lng' => (float) $location->lng,
                'zoom' => $zoom,
                'depth_cap' => $depthCap,
                'status' => 'pending',           // collected + finalized by the IngestCoverageScans sweep
                'scanned_at' => Carbon::now(),
            ]);

            foreach ($points as $i => $p) {
                GeoGridPoint::create([
                    'site_id' => $location->site_id,
                    'scan_id' => $scan->id,
                    'row' => $p['row'],
                    'col' => $p['col'],
                    'lat' => $p['lat'],
                    'lng' => $p['lng'],
                    'rank' => null,               // filled on collection
                    'provider_task_id' => $taskIds[$i] ?? null,
                    'collected_at' => null,       // null = still awaiting its task result
                    'coverage_area_id' => $p['coverage_area_id'],
                    'label' => $p['label'],
                ]);
            }

            return $scan;
        });
    }

    /**
     * Collect ready results for a PENDING scan's not-yet-collected points, up to $budget task_get calls (the
     * sweep's per-run rate budget). Each collected point gets its rank/competitors + a `collected_at` stamp;
     * points whose task isn't ready yet are left for the next sweep. When no uncollected point remains the scan
     * flips `pending → complete`. Returns the number of task_get calls actually spent (so the sweep can share
     * one budget across scans). Aggregates are the caller's to recompute once the scan finalizes.
     */
    public function collectPending(GeoGridScan $scan, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $location = Location::withoutGlobalScope(SiteScope::class)->find($scan->location_id);
        $placeId = trim((string) $location?->place_id);
        $cid = $this->cidFromGbpUrl((string) $location?->gbp_url);

        /** @var Collection<int, GeoGridPoint> $pending */
        $pending = $scan->points()
            ->whereNotNull('provider_task_id')
            ->whereNull('collected_at')
            ->get();

        $spent = 0;
        if ($pending->isNotEmpty()) {
            $ready = array_flip($this->client->tasksReady(self::MAPS_READY));

            foreach ($pending as $point) {
                if ($spent >= $budget) {
                    break;
                }
                $taskId = (string) $point->provider_task_id;
                if (! isset($ready[$taskId])) {
                    continue;   // not ready yet — leave for the next sweep
                }

                $items = DataForSeoClient::parseMaps($this->client->taskGet(self::MAPS_GET, $taskId));
                $result = $this->extract($items, $placeId, $cid);
                $point->forceFill([
                    'rank' => $result['rank'],
                    'competitors' => $result['competitors'],
                    'collected_at' => Carbon::now(),
                ])->save();
                $spent++;
            }
        }

        $this->finalizeIfComplete($scan);

        return $spent;
    }

    /** Flip a pending scan to `complete` once every point carrying a task id has been collected. */
    public function finalizeIfComplete(GeoGridScan $scan): void
    {
        if ($scan->status !== 'pending') {
            return;
        }
        $uncollected = $scan->points()
            ->whereNotNull('provider_task_id')
            ->whereNull('collected_at')
            ->count();
        if ($uncollected === 0) {
            $scan->forceFill(['status' => 'complete'])->save();
        }
    }

    /**
     * Build one DataForSEO Maps task per point and post them in ≤100-task chunks (the vendor's per-POST cap),
     * returning the created task ids in point order (task ids come back in the order posted).
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<string>
     */
    private function postTasks(array $points, Keyword $keyword): array
    {
        if ($points === []) {
            return [];
        }

        $zoom = (int) config('launchpad.geo_grid.zoom', 13);
        $depthCap = (int) config('launchpad.geo_grid.depth_cap', 20);
        $device = (string) config('launchpad.geo_grid.device', 'desktop');
        $language = (string) config('services.dataforseo.language_code', 'en');

        // One task per point, in point order. DataForSEO returns task ids in the order posted.
        $tasks = array_map(fn (array $p): array => [
            'keyword' => (string) $keyword->query,
            'location_coordinate' => sprintf('%.7f,%.7f,%d', $p['lat'], $p['lng'], $zoom),
            'language_code' => $language,
            'device' => $device,
            'depth' => $depthCap,
        ], $points);

        $ids = [];
        foreach (array_chunk($tasks, self::MAX_TASKS_PER_POST) as $chunk) {
            $ids = array_merge($ids, $this->client->taskPost(self::MAPS_POST, $chunk));
        }

        return $ids;
    }

    /**
     * Poll tasks_ready and task_get until every posted task is collected or the attempt ceiling is hit.
     *
     * @param  array<string, int>  $idToIndex  task id → point index
     * @return array<int, array{rank: int|null, competitors: list<array{name: string, place_id: string|null, rank: int|null}>}> point index → result
     */
    private function collect(array $idToIndex, string $placeId, string $cid): array
    {
        $interval = max(0, (int) config('launchpad.geo_grid.poll_interval_seconds', 5));
        $attempts = max(1, (int) config('launchpad.geo_grid.poll_max_attempts', 24));

        $pending = $idToIndex;
        $results = [];
        for ($attempt = 0; $attempt < $attempts && $pending !== []; $attempt++) {
            if ($attempt > 0 && $interval > 0) {
                sleep($interval);
            }
            $ready = array_flip($this->client->tasksReady(self::MAPS_READY));
            foreach ($pending as $taskId => $index) {
                if (! isset($ready[$taskId])) {
                    continue;
                }
                $items = DataForSeoClient::parseMaps($this->client->taskGet(self::MAPS_GET, $taskId));
                $results[$index] = $this->extract($items, $placeId, $cid);
                unset($pending[$taskId]);
            }
        }

        return $results;
    }

    /**
     * The business's rank at a cell (matched by place_id/CID, never name) + the top-3 competitors present.
     *
     * @param  list<array{rank: int|null, name: string, domain: string|null, place_id: string|null, cid: string|null}>  $items
     * @return array{rank: int|null, competitors: list<array{name: string, place_id: string|null, rank: int|null}>}
     */
    private function extract(array $items, string $placeId, string $cid): array
    {
        $rank = null;
        foreach ($items as $item) {
            $matchesPlace = $placeId !== '' && (string) $item['place_id'] === $placeId;
            $matchesCid = $cid !== '' && (string) $item['cid'] === $cid;
            if ($matchesPlace || $matchesCid) {
                $rank = $item['rank'];
                break;
            }
        }

        $competitors = collect($items)
            ->filter(fn (array $i): bool => $i['rank'] !== null
                && ! ($placeId !== '' && (string) $i['place_id'] === $placeId)
                && ! ($cid !== '' && (string) $i['cid'] === $cid))
            ->sortBy('rank')
            ->take(3)
            ->map(fn (array $i): array => ['name' => $i['name'], 'place_id' => $i['place_id'], 'rank' => $i['rank']])
            ->values()
            ->all();

        return ['rank' => $rank, 'competitors' => $competitors];
    }

    /** The CID from a GBP url's `?cid=` param, else '' — the secondary rank-match key. */
    private function cidFromGbpUrl(string $gbpUrl): string
    {
        $query = parse_url(trim($gbpUrl), PHP_URL_QUERY);
        if (! is_string($query)) {
            return '';
        }
        parse_str($query, $params);

        return isset($params['cid']) ? (string) $params['cid'] : '';
    }
}
