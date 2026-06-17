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

    private const BACKOFF_MS = 400;

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
     * @return list<string>
     */
    public function relatedKeywords(string $keyword, int $locationCode, string $language, int $limit): array
    {
        $json = $this->request('/v3/dataforseo_labs/google/related_keywords/live', [[
            'keyword' => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'limit' => $limit,
        ]]);

        return self::parseRelated($this->firstTaskResult($json));
    }

    // --- SERP API: organic (live) ---

    /**
     * @return list<array{position: int, url: string, domain: string}>
     */
    public function liveOrganic(string $keyword, int $locationCode, string $language, int $depth): array
    {
        $json = $this->request('/v3/serp/google/organic/live/advanced', [[
            'keyword' => $keyword,
            'location_code' => $locationCode,
            'language_code' => $language,
            'depth' => $depth,
        ]]);

        return self::parseOrganic($this->firstTaskResult($json));
    }

    // --- SERP API: local maps (live), one geo point ---

    /**
     * @return list<array{rank: int|null, name: string, domain: string|null}>
     */
    public function liveMaps(string $keyword, string $locationCoordinate, string $language): array
    {
        $json = $this->request('/v3/serp/google/maps/live/advanced', [[
            'keyword' => $keyword,
            'location_coordinate' => $locationCoordinate,
            'language_code' => $language,
        ]]);

        return self::parseMaps($this->firstTaskResult($json));
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
     * @param  array<int, mixed>  $result
     * @return list<array{position: int, url: string, domain: string}>
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
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $result
     * @return list<array{rank: int|null, name: string, domain: string|null}>
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
        return $this->handle($this->pending()->post($this->url($path), $body));
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
        return $this->handle($this->pending()->get($this->url($path)));
    }

    private function pending(): PendingRequest
    {
        return $this->http
            ->withBasicAuth($this->login, $this->password)
            ->timeout($this->timeout)
            ->acceptJson()
            ->retry(self::TRIES, self::BACKOFF_MS, function (Throwable $e): bool {
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
            throw new DataForSeoException(
                'DataForSEO HTTP '.$response->status(),
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
