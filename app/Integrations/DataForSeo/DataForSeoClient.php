<?php

namespace App\Integrations\DataForSeo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Low-level DataForSEO HTTP client. Owns Basic auth, the base URL, transient
 * retry with backoff, and the vendor's `status_code` envelope (20000 = ok) at
 * both the top level and per task. Auth/quota failures are surfaced loudly
 * (DataForSeoException, fatal) — never swallowed. Endpoint response parsing is
 * exposed as static helpers so the live path and the standard-mode ingest job
 * share one parser.
 */
class DataForSeoClient
{
    private const TRIES = 3;

    /** A read is cheap to abandon: one try, short ceiling. See {@see pending()}. */
    private const READ_TRIES = 1;

    private const READ_TIMEOUT_SECONDS = 15;

    private const BACKOFF_MS = 400;

    /** Reactive backoff on a 40202 rate-limit envelope (proactive throttle below usually prevents it). */
    private const RATE_LIMIT_RETRIES = 5;

    /** A 40101 "Internal SE Server Error" is a per-task hiccup on DataForSEO's side — one more try, then give up. */
    private const SE_ERROR_RETRIES = 1;

    private const SE_ERROR_BACKOFF_MS = 1500;

    /** Monotonic timestamps of the requests issued in the last 60s — the proactive per-minute throttle. */
    /** @var list<float> */
    private array $requestTimes = [];

    /**
     * The same window for the free, no-body READS (tasks_ready / task_get / user_data). A standard-queue
     * scan is one task_get per town, so these run under their own, far higher cap: at the post cap a
     * 1,444-town keyword would take two hours to collect.
     *
     * @var list<float>
     */
    private array $readTimes = [];

    public function __construct(
        private readonly Http $http,
        private readonly string $login,
        private readonly string $password,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {}

    // --- Keywords Data API: search volume (batch many keywords in one call) ---

    /**
     * @param  list<string>  $keywords
     * @return array<string, array{volume: int, cpc: float|null, competition: float|null}>
     */
    public function liveSearchVolume(array $keywords, int $locationCode, string $language): array
    {
        $json = $this->request('/v3/keywords_data/google_ads/search_volume/live', [[
            'keywords' => $keywords,
            'location_code' => $locationCode,
            'language_code' => $language,
        ]]);

        return self::parseSearchVolume($this->firstTaskResult($json));
    }

    /**
     * Search volume for a batch of keywords by location NAME (e.g. a DMA region or
     * state) — used by the Locations-grounded silo volume pass, which resolves metros
     * by name rather than by a hardcoded numeric code.
     *
     * @param  list<string>  $keywords
     * @return array<string, array{volume: int, cpc: float|null, competition: float|null}>
     */
    public function liveSearchVolumeByName(array $keywords, string $locationName, string $language): array
    {
        $json = $this->request('/v3/keywords_data/google_ads/search_volume/live', [[
            'keywords' => $keywords,
            'location_name' => $locationName,
            'language_code' => $language,
        ]]);

        return self::parseSearchVolume($this->firstTaskResult($json));
    }

    // --- Keywords Data API: Google Ads locations reference (for code resolution) ---

    /**
     * The Google Ads locations catalog (DMA regions, states, cities, …), optionally scoped
     * to one country (e.g. 'US'). Each row carries `location_code` / `location_name` /
     * `location_type`. A GET, no body. Used to resolve a covered metro to its numeric
     * `location_code` (string `location_name`s are fragile and silently un-matched).
     *
     * @return array<int, mixed>
     */
    public function googleAdsLocations(?string $country = null): array
    {
        $path = '/v3/keywords_data/google_ads/locations'.($country !== null && $country !== '' ? '/'.$country : '');

        return $this->firstTaskResult($this->requestGet($path));
    }

    // --- Labs API (synchronous only): difficulty (batch) + related ---

    /**
     * @param  list<string>  $keywords
     * @return array<string, int> keyword => difficulty (0-100)
     */
    public function bulkKeywordDifficulty(array $keywords, int $locationCode, string $language): array
    {
        $json = $this->request('/v3/dataforseo_labs/google/bulk_keyword_difficulty/live', [[
            'keywords' => $keywords,
            'location_code' => $locationCode,
            'language_code' => $language,
        ]]);

        return self::parseDifficulty($this->firstTaskResult($json));
    }

    /**
     * The strings-only related-keywords call. Kept for existing callers (the SERP provider's expansion
     * hint); prefer {@see relatedKeywordsWithMetrics()} when you need the volume/difficulty the endpoint
     * already returns.
     *
     * @return list<string>
     */
    public function relatedKeywords(string $keyword, int $locationCode, string $language, int $limit): array
    {
        return array_values(array_unique(array_map(
            fn (KeywordIdea $idea): string => $idea->keyword,
            $this->relatedKeywordsWithMetrics($keyword, $locationCode, $language, $limit),
        )));
    }

    /**
     * Related keywords WITH their metrics (volume / competition / difficulty) — the accumulation seam
     * for the keyword-first corpus builder. Same endpoint + cache path as {@see relatedKeywords()};
     * this variant just keeps the metrics the strings-only parser discards.
     *
     * @return list<KeywordIdea>
     */
    public function relatedKeywordsWithMetrics(string $keyword, int $locationCode, string $language, int $limit): array
    {
        $json = $this->request('/v3/dataforseo_labs/google/related_keywords/live', [[
            'keyword' => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'limit' => $limit,
        ]]);

        return self::parseRelatedIdeas($this->firstTaskResult($json));
    }

    // --- SERP API: organic (live) ---

    /**
     * @return list<array{position: int, url: string, domain: string, title?: string}>
     */
    public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
    {
        return self::parseOrganic($this->requestLiveTask('/v3/serp/google/organic/live/advanced', [
            'keyword' => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'depth' => $depth,
        ]));
    }

    // --- SERP API: local maps (live), one geo point ---

    /**
     * @return list<array{rank: int|null, name: string, domain: string|null, place_id: string|null, cid: string|null}>
     */
    public function liveMaps(string $keyword, string $locationCoordinate, string $language): array
    {
        return self::parseMaps($this->requestLiveTask('/v3/serp/google/maps/live/advanced', [
            'keyword' => $keyword,
            'location_coordinate' => $locationCoordinate,
            'language_code' => $language,
        ]));
    }

    // --- Backlinks API: a target's totals, and its links one per referring domain ---

    /**
     * @return array{backlinks: int, referring_domains: int, broken_backlinks: int, rank: int|null}
     */
    public function backlinksSummary(string $target): array
    {
        return self::parseBacklinkSummary($this->requestLiveTask('/v3/backlinks/summary/live', [
            'target' => $target,
            'include_subdomains' => true,
            'exclude_internal_backlinks' => true,
            'rank_scale' => 'one_hundred',
        ]));
    }

    /**
     * Live links to `$target`. `one_per_domain` returns each referring domain's strongest link, strongest
     * domains first; pass the previous page's `search_after_token` as `$after` to continue (all other
     * parameters must stay identical, which they do).
     *
     * @return array{items: list<array{domain_from: string, url_from: string, url_to: string, domain_from_rank: int|null, page_from_rank: int|null, spam_score: int|null, dofollow: bool, is_broken: bool, anchor: string|null, first_seen: string|null, last_seen: string|null}>, total_count: int, search_after_token: string|null}
     */
    public function backlinksLive(string $target, string $mode = 'one_per_domain', int $limit = 1000, ?string $after = null): array
    {
        $task = [
            'target' => $target,
            'mode' => $mode,
            'limit' => max(1, min(1000, $limit)),
            'order_by' => ['domain_from_rank,desc'],
            'backlinks_status_type' => 'live',
            'include_subdomains' => true,
            'exclude_internal_backlinks' => true,
            'rank_scale' => 'one_hundred',
        ];
        if ($after !== null && $after !== '') {
            $task['search_after_token'] = $after;
        }

        return self::parseBacklinks($this->requestLiveTask('/v3/backlinks/backlinks/live', $task));
    }

    /**
     * @param  array<int, mixed>  $result
     * @return array{backlinks: int, referring_domains: int, broken_backlinks: int, rank: int|null}
     */
    public static function parseBacklinkSummary(array $result): array
    {
        $row = is_array($result[0] ?? null) ? $result[0] : [];

        return [
            'backlinks' => (int) ($row['backlinks'] ?? 0),
            'referring_domains' => (int) ($row['referring_domains'] ?? 0),
            'broken_backlinks' => (int) ($row['broken_backlinks'] ?? 0),
            'rank' => isset($row['rank']) && is_numeric($row['rank']) ? (int) $row['rank'] : null,
        ];
    }

    /**
     * @param  array<int, mixed>  $result
     * @return array{items: list<array{domain_from: string, url_from: string, url_to: string, domain_from_rank: int|null, page_from_rank: int|null, spam_score: int|null, dofollow: bool, is_broken: bool, anchor: string|null, first_seen: string|null, last_seen: string|null}>, total_count: int, search_after_token: string|null}
     */
    public static function parseBacklinks(array $result): array
    {
        $page = is_array($result[0] ?? null) ? $result[0] : [];
        $int = static fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;
        $date = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? substr($v, 0, 10) : null;
        $items = [];
        foreach (is_array($page['items'] ?? null) ? $page['items'] : [] as $item) {
            if (! is_array($item) || ! is_string($item['domain_from'] ?? null) || $item['domain_from'] === '') {
                continue;
            }
            $items[] = [
                'domain_from' => strtolower((string) preg_replace('/^www\./i', '', $item['domain_from'])),
                'url_from' => (string) ($item['url_from'] ?? ''),
                'url_to' => (string) ($item['url_to'] ?? ''),
                'domain_from_rank' => $int($item['domain_from_rank'] ?? null),
                'page_from_rank' => $int($item['page_from_rank'] ?? null),
                'spam_score' => $int($item['backlink_spam_score'] ?? null),
                'dofollow' => (bool) ($item['dofollow'] ?? true),
                'is_broken' => (bool) ($item['is_broken'] ?? false),
                'anchor' => isset($item['anchor']) && is_string($item['anchor']) && $item['anchor'] !== '' ? $item['anchor'] : null,
                'first_seen' => $date($item['first_seen'] ?? null),
                'last_seen' => $date($item['last_seen'] ?? null),
            ];
        }
        $token = $page['search_after_token'] ?? null;

        return [
            'items' => $items,
            'total_count' => (int) ($page['total_count'] ?? count($items)),
            'search_after_token' => is_string($token) && $token !== '' ? $token : null,
        ];
    }

    /**
     * Referring domains that link to at least one of `$targets` and to none of `$exclude`
     * (`backlinks/domain_intersection/live`, partial mode). Targets are keyed "1".."20" on the wire; the
     * result names each domain's linked targets by the strings passed in.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $exclude
     * @return list<array{domain: string, rank: int|null, spam_score: int|null, linked_targets: list<string>, first_seen: string|null}>
     */
    public function backlinksDomainIntersection(array $targets, array $exclude, int $limit = 300): array
    {
        $targets = array_slice(array_values(array_unique(array_filter($targets))), 0, 20);
        if ($targets === []) {
            return [];
        }
        $keyed = [];
        foreach ($targets as $i => $t) {
            $keyed[$i + 1] = $t; // 1-based int keys encode as the {"1": …} object the API wants
        }
        $task = [
            'targets' => $keyed,
            'intersection_mode' => 'partial',
            'backlinks_status_type' => 'live',
            'include_subdomains' => true,
            'exclude_internal_backlinks' => true,
            'rank_scale' => 'one_hundred',
            'limit' => max(1, min(1000, $limit)),
        ];
        $exclude = array_slice(array_values(array_unique(array_filter($exclude))), 0, 10);
        if ($exclude !== []) {
            $task['exclude_targets'] = $exclude;
        }

        return self::parseDomainIntersection($this->requestLiveTask('/v3/backlinks/domain_intersection/live', $task), $keyed);
    }

    /**
     * @param  array<int, mixed>  $result
     * @param  array<int, string>  $keyed  the wire keys (1-based) → target strings
     * @return list<array{domain: string, rank: int|null, spam_score: int|null, linked_targets: list<string>, first_seen: string|null}>
     */
    public static function parseDomainIntersection(array $result, array $keyed): array
    {
        $page = is_array($result[0] ?? null) ? $result[0] : [];
        $out = [];
        foreach (is_array($page['items'] ?? null) ? $page['items'] : [] as $item) {
            $per = is_array($item['domain_intersection'] ?? null) ? $item['domain_intersection'] : [];
            $domain = null;
            $rank = null;
            $spam = null;
            $first = null;
            $linked = [];
            foreach ($per as $key => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $linked[] = $keyed[(int) $key] ?? (string) $key;
                $domain ??= isset($entry['target']) && is_string($entry['target']) ? strtolower((string) preg_replace('/^www\\./i', '', $entry['target'])) : null;
                $rank = max($rank ?? 0, is_numeric($entry['rank'] ?? null) ? (int) $entry['rank'] : 0);
                $spam = max($spam ?? 0, is_numeric($entry['backlinks_spam_score'] ?? null) ? (int) $entry['backlinks_spam_score'] : 0);
                $seen = isset($entry['first_seen']) && is_string($entry['first_seen']) ? substr($entry['first_seen'], 0, 10) : null;
                $first = $seen !== null && ($first === null || $seen < $first) ? $seen : $first;
            }
            if ($domain === null && isset($item['domain']) && is_string($item['domain'])) {
                $domain = strtolower((string) preg_replace('/^www\\./i', '', $item['domain']));
            }
            if ($domain === null || $domain === '' || $linked === []) {
                continue;
            }
            $out[] = ['domain' => $domain, 'rank' => $rank, 'spam_score' => $spam, 'linked_targets' => array_values(array_unique($linked)), 'first_seen' => $first];
        }

        return $out;
    }

    // --- Standard-mode task lifecycle (generic over an endpoint family) ---

    /**
     * Post one or more tasks; returns the DataForSEO task ids created.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<string>
     */
    public function taskPost(string $postPath, array $tasks): array
    {
        $json = $this->request($postPath, $tasks);

        $ids = [];
        foreach (($json['tasks'] ?? []) as $task) {
            if (isset($task['id'])) {
                $ids[] = (string) $task['id'];
            }
        }

        return $ids;
    }

    /**
     * The task ids ready to collect for an endpoint family.
     *
     * @return list<string>
     */
    public function tasksReady(string $readyPath): array
    {
        $json = $this->requestGet($readyPath);

        $ids = [];
        foreach (($json['tasks'] ?? []) as $task) {
            foreach (($task['result'] ?? []) as $ready) {
                if (isset($ready['id'])) {
                    $ids[] = (string) $ready['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Collect a ready task's result payload (the inner `result` array).
     *
     * @return array<int, mixed>
     */
    public function taskGet(string $getPath, string $taskId): array
    {
        $json = $this->requestGet(rtrim($getPath, '/').'/'.$taskId);

        return $this->firstTaskResult($json);
    }

    // --- Probe: zero-cost account endpoint ---

    /**
     * @return array{login: string, balance: float|null}
     */
    public function userData(): array
    {
        $json = $this->requestGet('/v3/appendix/user_data');
        $row = $this->firstTaskResult($json)[0] ?? [];
        $row = is_array($row) ? $row : [];

        return [
            'login' => (string) ($row['login'] ?? $this->login),
            'balance' => isset($row['money']['balance']) ? (float) $row['money']['balance'] : null,
        ];
    }

    // ----------------------------------------------------------------------
    // Parsers (shared by the live path and the standard-mode ingest job)
    // ----------------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $result
     * @return array<string, array{volume: int, cpc: float|null, competition: float|null}>
     */
    public static function parseSearchVolume(array $result): array
    {
        $out = [];
        foreach ($result as $row) {
            if (! is_array($row) || ! isset($row['keyword'])) {
                continue;
            }
            $out[(string) $row['keyword']] = [
                'volume' => (int) ($row['search_volume'] ?? 0),
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                'competition' => isset($row['competition_index']) ? (float) $row['competition_index'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $result
     * @return array<string, int>
     */
    public static function parseDifficulty(array $result): array
    {
        $out = [];
        foreach ($result as $row) {
            if (is_array($row) && isset($row['keyword'])) {
                $out[(string) $row['keyword']] = (int) ($row['keyword_difficulty'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $result
     * @return list<string>
     */
    public static function parseRelated(array $result): array
    {
        $terms = [];
        foreach ($result as $row) {
            $term = $row['keyword_data']['keyword'] ?? ($row['keyword'] ?? null);
            if (is_string($term) && $term !== '') {
                $terms[] = $term;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Parse related_keywords items into metric-carrying ideas. Each item nests the term + metrics under
     * `keyword_data` (`keyword_info.search_volume` / `.competition`, `keyword_properties.keyword_difficulty`);
     * defensive against missing nodes. Deduped by keyword, keeping the first (highest-ranked) occurrence.
     *
     * @param  array<int, mixed>  $result
     * @return list<KeywordIdea>
     */
    public static function parseRelatedIdeas(array $result): array
    {
        $out = [];
        foreach ($result as $row) {
            $data = is_array($row['keyword_data'] ?? null) ? $row['keyword_data'] : (is_array($row) ? $row : []);
            $term = $data['keyword'] ?? ($row['keyword'] ?? null);
            if (! is_string($term) || $term === '' || isset($out[$term])) {
                continue;
            }

            $info = is_array($data['keyword_info'] ?? null) ? $data['keyword_info'] : [];
            $props = is_array($data['keyword_properties'] ?? null) ? $data['keyword_properties'] : [];

            $out[$term] = new KeywordIdea(
                keyword: $term,
                volume: (int) ($info['search_volume'] ?? 0),
                competition: isset($info['competition']) && is_numeric($info['competition']) ? (float) $info['competition'] : null,
                difficulty: isset($props['keyword_difficulty']) && is_numeric($props['keyword_difficulty']) ? (int) $props['keyword_difficulty'] : null,
            );
        }

        return array_values($out);
    }

    /**
     * @param  array<int, mixed>  $result
     * @return list<array{position: int, url: string, domain: string, title: string}>
     */
    public static function parseOrganic(array $result): array
    {
        $items = $result[0]['items'] ?? [];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'organic') {
                continue;
            }
            $out[] = [
                'position' => (int) ($item['rank_absolute'] ?? $item['rank_group'] ?? 0),
                'url' => (string) ($item['url'] ?? ''),
                'domain' => (string) ($item['domain'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $result
     * @return list<array{rank: int|null, name: string, domain: string|null, place_id: string|null, cid: string|null}>
     */
    public static function parseMaps(array $result): array
    {
        $items = $result[0]['items'] ?? [];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'maps_search') {
                continue;
            }
            $out[] = [
                'rank' => isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null,
                'name' => (string) ($item['title'] ?? ''),
                'domain' => isset($item['domain']) ? (string) $item['domain'] : null,
                // Kept for geo-grid place_id/CID rank matching (never business name). §5's domain-matched
                // local grid ignores these extra keys.
                'place_id' => isset($item['place_id']) ? (string) $item['place_id'] : null,
                'cid' => isset($item['cid']) ? (string) $item['cid'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, mixed>
     */
    private function firstTaskResult(array $json): array
    {
        $task = $json['tasks'][0] ?? null;
        if (! is_array($task)) {
            return [];
        }

        $taskStatus = (int) ($task['status_code'] ?? 0);
        if ($taskStatus !== 20000) {
            throw DataForSeoException::envelope($taskStatus, (string) ($task['status_message'] ?? 'task error'));
        }

        $result = $task['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * POST a JSON body — the task/live endpoints, which carry an array-of-tasks.
     *
     * @param  array<int|string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $path, array $body): array
    {
        return $this->send(fn (): array => $this->handle($this->pending()->post($this->url($path), $body)));
    }

    /**
     * POST one live task and return its result — the TASK-level status is checked inside the retry loop, so a
     * per-task transient (40101 "Internal SE Server Error" comes back at task level, not the envelope) gets
     * its retry too.
     *
     * @param  array<string, mixed>  $task
     * @return array<int, mixed>
     */
    private function requestLiveTask(string $path, array $task): array
    {
        return $this->send(fn (): array => $this->firstTaskResult($this->handle($this->pending()->post($this->url($path), [$task]))));
    }

    /**
     * Issue a request under the DataForSEO rate limit: throttle proactively to stay under the per-minute
     * cap (so a discovery burst never trips it), and — if the cap is hit anyway (e.g. a concurrent run) —
     * back off and retry the transient 40202 instead of crashing the whole run. A 40101 (DataForSEO's own
     * SE fetch failed) gets one more try. Any other envelope error (auth/quota, fatal) surfaces immediately.
     *
     * @template T of array
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function send(callable $fn, bool $read = false): array
    {
        $this->throttle($read);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (DataForSeoException $e) {
                if ($e->statusCode === DataForSeoException::SE_ERROR) {
                    if ($attempt > self::SE_ERROR_RETRIES) {
                        throw $e;
                    }
                    usleep(self::SE_ERROR_BACKOFF_MS * 1000);

                    continue;
                }
                if ($e->statusCode !== DataForSeoException::RATE_LIMITED || $attempt > self::RATE_LIMIT_RETRIES) {
                    throw $e;
                }
                $backoffMs = (int) config('services.dataforseo.rate_limit_backoff_ms', 5000);
                if ($backoffMs > 0) {
                    usleep($backoffMs * 1000 * $attempt); // escalating — clear the rolling per-minute window
                }
            }
        }
    }

    /**
     * Space requests to stay under DataForSEO's per-minute cap: once the rolling 60s window is full, sleep
     * until the oldest request ages out, then record this one. Posts (which spend credits) run under
     * `rate_limit_per_min` (default 12); reads under `read_rate_limit_per_min` (default 600 — DataForSEO
     * allows 2,000 calls/min), each in its own window.
     */
    private function throttle(bool $read = false): void
    {
        $perMin = $read
            ? max(1, (int) config('services.dataforseo.read_rate_limit_per_min', 600))
            : max(1, (int) config('services.dataforseo.rate_limit_per_min', 12));
        $times = $read ? $this->readTimes : $this->requestTimes;
        $now = microtime(true);
        $times = array_values(array_filter($times, fn (float $t): bool => $now - $t < 60.0));

        if (count($times) >= $perMin) {
            $wait = 60.0 - ($now - $times[0]) + 0.25;
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $now = microtime(true);
            $times = array_values(array_filter($times, fn (float $t): bool => $now - $t < 60.0));
        }

        $times[] = microtime(true);
        if ($read) {
            $this->readTimes = $times;
        } else {
            $this->requestTimes = $times;
        }
    }

    /**
     * GET a no-body endpoint. DataForSEO's appendix/user_data, tasks_ready, and
     * task_get take NO POST body — sending one as a POST yields status_code 40502
     * "POST Data Is Empty", so they must be issued as GET.
     *
     * @return array<string, mixed>
     */
    private function requestGet(string $path): array
    {
        return $this->send(fn (): array => $this->handle($this->pending(read: true)->get($this->url($path))), read: true);
    }

    /**
     * A READ fails fast. A post spends credits, so it is worth three tries over 30s each; a task_get spends
     * nothing and the town it belongs to is simply left for the next pass, so retrying hard inside the job
     * buys nothing and costs the worker: at 3 × 30s a single stuck read can eat 90 seconds of a job's
     * budget, which is what forces a collector to reserve a 100-second margin and stay large. One try at
     * {@see READ_TIMEOUT_SECONDS} bounds the worst case to seconds, which is what lets the jobs be small.
     */
    private function pending(bool $read = false): PendingRequest
    {
        return $this->http
            ->withBasicAuth($this->login, $this->password)
            ->timeout($read ? self::READ_TIMEOUT_SECONDS : $this->timeout)
            ->acceptJson()
            ->retry($read ? self::READ_TRIES : self::TRIES, self::BACKOFF_MS, function (Throwable $e): bool {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError());
            }, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response): array
    {
        if (! $response->successful()) {
            // The vendor's own words when the body carries them — a 500 on a malformed intersect says
            // what it objected to; without this the operator sees only the number.
            $body = $response->json();
            $detail = is_array($body) && is_string($body['status_message'] ?? null)
                ? $body['status_message']
                : trim(mb_substr(strip_tags((string) $response->body()), 0, 200));

            throw new DataForSeoException(
                'DataForSEO HTTP '.$response->status().($detail !== '' ? ': '.$detail : ''),
                $response->status(),
                fatal: in_array($response->status(), [401, 402, 403], true),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new DataForSeoException('DataForSEO returned a non-JSON body.');
        }

        $top = (int) ($json['status_code'] ?? 0);
        if ($top !== 20000) {
            throw DataForSeoException::envelope($top, (string) ($json['status_message'] ?? 'unknown'));
        }

        return $json;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
