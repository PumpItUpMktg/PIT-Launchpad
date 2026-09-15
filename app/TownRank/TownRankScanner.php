<?php

namespace App\TownRank;

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Scans the WEBSITE's organic rank per covered town (§ Town Rank) via DataForSEO Google organic SERP, standard
 * queue (task_post → tasks_ready → task_get — never live: at hundreds of towns the price difference compounds).
 * Two query modes per keyword, each its own scan:
 *
 *  - `local`: the bare keyword searched FROM each town's centroid (`location_coordinate`) — what a resident
 *    sees for "sump pump repair" without typing the town; local intent dominates, so ranks vary by town.
 *  - `town_query`: "{keyword} {Town} {ST}" searched from the national location — whether the town PAGE is
 *    the one Google serves for the explicit town search.
 *
 * Rank is the first result whose domain is the site's own host (www-insensitive), never a title match. Posting
 * is fast and persists a PENDING scan with a point per town; collection ({@see collectPending}) is bounded
 * per call so a whole-site scan never overruns one job — the CLI polls, and a later sweep can share the same
 * method. The caller owns the hard per-run request ceiling.
 */
final class TownRankScanner
{
    private const ORGANIC_POST = '/v3/serp/google/organic/task_post';

    private const ORGANIC_READY = '/v3/serp/google/organic/tasks_ready';

    private const ORGANIC_GET = '/v3/serp/google/organic/task_get/advanced';

    /** DataForSEO accepts at most 100 tasks per task_post. */
    private const MAX_TASKS_PER_POST = 100;

    /** How many of the town's results to keep for "who outranks us here". */
    private const TOP_RESULTS = 5;

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly TownRankPoints $points,
    ) {}

    /** The exact query a mode sends for a town. */
    public static function queryFor(string $mode, string $keyword, string $town, ?string $state): string
    {
        if ($mode === TownRankScan::MODE_TOWN_QUERY) {
            return trim(preg_replace('/\s+/', ' ', "{$keyword} {$town} ".(string) $state) ?? '');
        }

        return trim($keyword);
    }

    /**
     * Post one organic task per covered town and persist a PENDING scan. Returns null when the site has no
     * scannable towns.
     */
    public function post(Site $site, Keyword $keyword, string $mode): ?TownRankScan
    {
        $towns = $this->points->forSite($site);
        if ($towns === []) {
            return null;
        }

        $tasks = array_map(fn (array $t): array => $this->task($mode, (string) $keyword->query, $t), $towns);

        $taskIds = [];
        foreach (array_chunk($tasks, self::MAX_TASKS_PER_POST) as $chunk) {
            $taskIds = array_merge($taskIds, $this->client->taskPost(self::ORGANIC_POST, $chunk));
        }

        return DB::transaction(function () use ($site, $keyword, $mode, $towns, $tasks, $taskIds): TownRankScan {
            $scan = TownRankScan::create([
                'site_id' => $site->id,
                'keyword_id' => $keyword->id,
                'mode' => $mode,
                'provider' => 'dataforseo',
                'status' => 'pending',
                'points_count' => count($towns),
                'found_count' => 0,
                'scanned_at' => Carbon::now(),
            ]);

            foreach ($towns as $i => $town) {
                TownRankPoint::create([
                    'site_id' => $site->id,
                    'scan_id' => $scan->id,
                    'coverage_area_id' => $town['coverage_area_id'],
                    'label' => $town['label'],
                    'state' => $town['state'],
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'query' => (string) $tasks[$i]['keyword'],
                    'rank' => null,
                    'provider_task_id' => $taskIds[$i] ?? null,   // task ids come back in the order posted
                    'collected_at' => null,
                ]);
            }

            return $scan;
        });
    }

    /**
     * Collect ready results for a pending scan's uncollected points, up to $budget task_get calls and, when
     * given, a wall-clock $deadline (microtime) so a queued caller stops well inside its own timeout. A point
     * whose task isn't ready is left for the next call; a task DataForSEO reports as errored (e.g. "No Search
     * Results") is collected as rank null so it can't block completion. Returns task_get calls spent.
     */
    public function collectPending(TownRankScan $scan, int $budget, ?float $deadline = null): int
    {
        if ($budget <= 0 || $scan->status !== 'pending' || ($deadline !== null && microtime(true) >= $deadline)) {
            return 0;
        }

        /** @var Collection<int, TownRankPoint> $pending */
        $pending = $scan->points()->whereNotNull('provider_task_id')->whereNull('collected_at')->get();

        $spent = 0;
        if ($pending->isNotEmpty()) {
            $site = Site::withoutGlobalScopes()->find($scan->site_id);
            $host = self::host($site?->domain_url);
            $ready = array_flip($this->client->tasksReady(self::ORGANIC_READY));

            foreach ($pending as $point) {
                if ($spent >= $budget || ($deadline !== null && microtime(true) >= $deadline)) {
                    break;   // the caller's budget or wall-clock is spent; the rest waits for the next run
                }
                $taskId = (string) $point->provider_task_id;
                if (! isset($ready[$taskId])) {
                    continue;
                }

                try {
                    $items = DataForSeoClient::parseOrganic($this->client->taskGet(self::ORGANIC_GET, $taskId));
                } catch (Throwable) {
                    $items = [];   // an errored task: collected, not found
                }
                $point->forceFill([...$this->extract($items, $host), 'collected_at' => Carbon::now()])->save();
                $spent++;
            }
        }

        $this->finalizeIfComplete($scan);

        return $spent;
    }

    /** Flip a pending scan to `complete` once every point carrying a task id has been collected. */
    public function finalizeIfComplete(TownRankScan $scan): void
    {
        if ($scan->status !== 'pending') {
            return;
        }
        $uncollected = $scan->points()->whereNotNull('provider_task_id')->whereNull('collected_at')->count();
        if ($uncollected === 0) {
            $this->finalize($scan, 'complete');
        }
    }

    /** Close a scan over whatever it has (the expiry path). */
    public function finalize(TownRankScan $scan, string $status): void
    {
        $scan->forceFill([
            'status' => $status,
            'found_count' => $scan->points()->whereNotNull('rank')->count(),
        ])->save();
    }

    /**
     * @param  array{name: string, state: string|null, lat: float, lng: float}  $town
     * @return array<string, mixed>
     */
    private function task(string $mode, string $keyword, array $town): array
    {
        $task = [
            'keyword' => self::queryFor($mode, $keyword, $town['name'], $town['state']),   // the bare name, never the display label
            'language_code' => (string) config('services.dataforseo.language_code', 'en'),
            'device' => (string) config('launchpad.town_rank.device', 'desktop'),
            'depth' => (int) config('launchpad.town_rank.depth', 30),
        ];
        if ($mode === TownRankScan::MODE_LOCAL) {
            $task['location_coordinate'] = sprintf('%.7f,%.7f', $town['lat'], $town['lng']);
        } else {
            $task['location_code'] = (int) config('services.dataforseo.location_code', 2840);
        }

        return $task;
    }

    /**
     * The site's rank + ranking URL at a town (first result on the site's own host) and the top results.
     *
     * @param  list<array{position: int, url: string, domain: string}>  $items
     * @return array{rank: int|null, ranking_url: string|null, top_results: list<array{position: int, url: string, domain: string}>}
     */
    private function extract(array $items, ?string $host): array
    {
        usort($items, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $rank = null;
        $url = null;
        if ($host !== null) {
            foreach ($items as $item) {
                if (self::sameHost($item['domain'], $host)) {
                    $rank = $item['position'] > 0 ? $item['position'] : null;
                    $url = $item['url'] !== '' ? $item['url'] : null;
                    break;
                }
            }
        }

        return [
            'rank' => $rank,
            'ranking_url' => $url,
            'top_results' => array_slice($items, 0, self::TOP_RESULTS),
        ];
    }

    /** The site's bare host ("spg.com" from "https://www.spg.com/"), null when the site has no domain. */
    public static function host(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }

    private static function sameHost(string $domain, string $host): bool
    {
        $domain = strtolower(preg_replace('/^www\./i', '', trim($domain)) ?? $domain);

        return $domain === $host;
    }
}
