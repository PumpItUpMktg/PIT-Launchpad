<?php

namespace App\Integrations\DataForSeo;

use App\Enums\DataForSeoMode;
use App\Integrations\Serp\KeywordMetrics;
use App\Integrations\Serp\SerpProvider;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Live DataForSEO SerpProvider. Supplies NORMALIZED signals only — §5 keeps
 * computing opportunity and beatability.
 *
 *  - metrics(): assembled from Keywords Data (search_volume) + Labs
 *    (bulk_keyword_difficulty, related_keywords). Labs is synchronous-only, so
 *    metrics is fetched live and cached for the cadence window.
 *  - results(): SERP google/organic. Honors the configured mode — standard
 *    (task_post → ingest, cached) or live (synchronous). On a standard cache
 *    miss it dispatches a deduped task and returns an empty set until ingested.
 */
class DataForSeoSerpProvider implements SerpProvider
{
    public const ORGANIC_POST = '/v3/serp/google/organic/task_post';

    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly SerpTaskDispatcher $dispatcher,
        private readonly Cache $cache,
        private readonly DataForSeoMode $mode,
        private readonly int $locationCode,
        private readonly string $language,
        private readonly int $serpDepth,
        private readonly int $relatedLimit,
        private readonly int $cacheTtlHours,
    ) {}

    public function metrics(string $query): KeywordMetrics
    {
        /** @var KeywordMetrics */
        return $this->cache->remember($this->metricsKey($query), $this->ttl(), function () use ($query): KeywordMetrics {
            $volume = $this->client->liveSearchVolume([$query], $this->locationCode, $this->language)[$query]['volume'] ?? 0;
            $difficulty = $this->client->bulkKeywordDifficulty([$query], $this->locationCode, $this->language)[$query] ?? 0;
            $related = $this->client->relatedKeywords($query, $this->locationCode, $this->language, $this->relatedLimit);

            return new KeywordMetrics($query, (int) $volume, (int) $difficulty, $related);
        });
    }

    public function results(string $query): SerpResultSet
    {
        $key = $this->resultsKey($query);

        $cached = $this->cache->get($key);
        if ($cached instanceof SerpResultSet) {
            return $cached;
        }

        if ($this->mode === DataForSeoMode::Live) {
            $set = $this->toResultSet($query, $this->client->liveOrganic($query, $this->locationCode, $this->language, $this->serpDepth));
            $this->cache->put($key, $set, $this->ttl());

            return $set;
        }

        // Standard: dispatch a deduped task; the result lands via the ingest sweep.
        $this->dispatcher->ensure('organic', $key, self::ORGANIC_POST, [
            'keyword' => $query,
            'location_code' => $this->locationCode,
            'language_code' => $this->language,
            'depth' => $this->serpDepth,
        ]);

        return new SerpResultSet($query, []);
    }

    /**
     * Batch-warm keyword metrics for many queries in one round of calls (cost
     * discipline — one search_volume + one difficulty call covers all).
     *
     * @param  list<string>  $queries
     */
    public function warm(array $queries): void
    {
        $queries = array_values(array_filter($queries, fn (string $q) => ! $this->cache->has($this->metricsKey($q))));
        if ($queries === []) {
            return;
        }

        $volumes = $this->client->liveSearchVolume($queries, $this->locationCode, $this->language);
        $difficulties = $this->client->bulkKeywordDifficulty($queries, $this->locationCode, $this->language);

        foreach ($queries as $query) {
            $related = $this->client->relatedKeywords($query, $this->locationCode, $this->language, $this->relatedLimit);
            $this->cache->put($this->metricsKey($query), new KeywordMetrics(
                $query,
                (int) ($volumes[$query]['volume'] ?? 0),
                (int) ($difficulties[$query] ?? 0),
                $related,
            ), $this->ttl());
        }
    }

    /**
     * @param  list<array{position: int, url: string, domain: string}>  $items
     */
    private function toResultSet(string $query, array $items): SerpResultSet
    {
        $results = array_map(
            fn (array $i) => new SerpResult($i['position'], $i['url'], $i['domain']),
            $items,
        );

        return new SerpResultSet($query, $results);
    }

    private function metricsKey(string $query): string
    {
        return "dfs:metrics:{$this->locationCode}:{$this->language}:".md5($query);
    }

    private function resultsKey(string $query): string
    {
        return "dfs:organic:{$this->locationCode}:{$this->language}:".md5($query);
    }

    private function ttl(): int
    {
        return $this->cacheTtlHours * 3600;
    }
}
