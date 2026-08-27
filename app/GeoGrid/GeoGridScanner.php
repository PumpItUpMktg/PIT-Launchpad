<?php

namespace App\GeoGrid;

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use Illuminate\Support\Carbon;
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
 */
final class GeoGridScanner
{
    private const MAPS_POST = '/v3/serp/google/maps/task_post';

    private const MAPS_READY = '/v3/serp/google/maps/tasks_ready';

    private const MAPS_GET = '/v3/serp/google/maps/task_get/advanced';

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly GeoGridGeometry $geometry,
    ) {}

    public function scan(Location $location, Keyword $keyword, ?float $spacingMilesOverride = null): GeoGridScan
    {
        $gridSize = max(1, (int) config('launchpad.geo_grid.grid_size', 7));
        $zoom = (int) config('launchpad.geo_grid.zoom', 13);
        $depthCap = (int) config('launchpad.geo_grid.depth_cap', 20);
        $device = (string) config('launchpad.geo_grid.device', 'desktop');
        $language = (string) config('services.dataforseo.language_code', 'en');
        $spacing = $spacingMilesOverride ?? $location->gridSpacingMiles();
        $centerLat = (float) $location->lat;
        $centerLng = (float) $location->lng;

        $points = $this->geometry->points($centerLat, $centerLng, $gridSize, $spacing);

        // One task per point, in point order. DataForSEO returns task ids in the order posted.
        $tasks = array_map(fn (array $p): array => [
            'keyword' => (string) $keyword->query,
            'location_coordinate' => sprintf('%.7f,%.7f,%d', $p['lat'], $p['lng'], $zoom),
            'language_code' => $language,
            'device' => $device,
            'depth' => $depthCap,
        ], $points);

        $taskIds = $this->client->taskPost(self::MAPS_POST, $tasks);

        // task id → point index (only the ids we got back, positionally).
        $idToIndex = [];
        foreach ($taskIds as $i => $taskId) {
            $idToIndex[$taskId] = $i;
        }

        $placeId = trim((string) $location->place_id);
        $cid = $this->cidFromGbpUrl((string) $location->gbp_url);

        // Poll standard-queue tasks until ours are ready (bounded), collecting each result.
        $results = $this->collect($idToIndex, $placeId, $cid);

        return DB::transaction(function () use ($location, $keyword, $points, $taskIds, $idToIndex, $results, $gridSize, $spacing, $centerLat, $centerLng, $zoom, $depthCap): GeoGridScan {
            $scan = GeoGridScan::create([
                'site_id' => $location->site_id,
                'location_id' => $location->id,
                'keyword_id' => $keyword->id,
                'provider' => 'dataforseo',
                'provider_scan_id' => $taskIds[0] ?? null,
                'grid_size' => $gridSize,
                'spacing_miles' => $spacing,
                'center_lat' => $centerLat,
                'center_lng' => $centerLng,
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
                ]);
            }

            return $scan;
        });
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
