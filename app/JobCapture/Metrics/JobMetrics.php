<?php

namespace App\JobCapture\Metrics;

use App\Guided\LiveMetrics;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\SearchConsoleProvider;
use App\Integrations\UrlInspection\IndexInspector;
use App\Models\Job;
use App\Models\Site;

/**
 * The Published-Jobs card tracking block — the Job Capture twin of {@see LiveMetrics}, keyed on
 * the job's public URL/path ({@see Job::publicUrl()} / {@see Job::publicPath()}) instead of a Content slug.
 *
 * A job page is proof content, NOT a keyword target — so there is NO position/ranking block (tracked SERP
 * position doesn't apply and would be misleading). The honest per-job signals are: Google INDEX coverage
 * (the URL-Inspection verdict, cache-only), GSC impressions/clicks/CTR + the queries the page is found for
 * (the free "is it getting found, and for what"), and GA4 sessions. Every cell degrades honestly — a metric
 * renders only when its source is connected and has data, else a specific pending reason, never a fake zero.
 */
class JobMetrics
{
    public function __construct(
        private readonly SearchConsoleProvider $searchConsole,
        private readonly IndexInspector $indexInspector,
        private readonly PageTrafficProvider $traffic,
    ) {}

    /**
     * @return array{
     *   gsc: array{impressions: ?int, clicks: ?int, ctr: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   index: array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, last_crawled_at: ?string, pending: ?string},
     *   traffic: array{sessions: ?int, pending: ?string}
     * }
     */
    public function for(Job $job): array
    {
        $site = $job->site;

        return [
            'gsc' => $this->gscBlock($job, $site),
            'index' => $this->indexBlock($job, $site),
            'traffic' => $this->trafficBlock($job, $site),
        ];
    }

    /**
     * @return array{impressions: ?int, clicks: ?int, ctr: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string}
     */
    private function gscBlock(Job $job, ?Site $site): array
    {
        if ($site === null || ! $this->searchConsole->connected($site)) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_google' => false, 'queries' => [], 'pending' => 'Connect Search Console'];
        }

        $stats = $this->searchConsole->pageStats($site, $job->publicPath());
        if ($stats === null) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_google' => false, 'queries' => [], 'pending' => 'Collecting — first data in a few days'];
        }

        $queries = array_map(fn (PageQuery $q): array => [
            'query' => $q->query, 'clicks' => $q->clicks, 'impressions' => $q->impressions, 'ctr' => $q->ctr, 'position' => $q->position,
        ], $this->searchConsole->pageQueries($site, $job->publicPath()));

        return ['impressions' => $stats->impressions, 'clicks' => $stats->clicks, 'ctr' => $stats->ctr(), 'in_google' => $stats->impressions > 0, 'queries' => $queries, 'pending' => null];
    }

    /**
     * @return array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, last_crawled_at: ?string, pending: ?string}
     */
    private function indexBlock(Job $job, ?Site $site): array
    {
        $blank = ['state' => null, 'label' => null, 'indexed' => false, 'coverage_state' => null, 'canonical_mismatch' => false, 'last_crawled_at' => null];

        if ($site === null || ! $this->indexInspector->connected($site)) {
            return $blank + ['pending' => 'Connect Search Console'];
        }

        // Cache-only (URL Inspection is quota-limited): lights up once launchpad:audit-index has inspected
        // the job URL — the same trailing-slash form the audit caches.
        $url = $job->publicUrl($site->domain_url);
        $status = $url !== null ? $this->indexInspector->cached($site, $url) : null;
        if ($status === null) {
            return $blank + ['pending' => 'Run index audit'];
        }

        return [
            'state' => $status->state->value,
            'label' => $status->state->label(),
            'indexed' => $status->indexed(),
            'coverage_state' => $status->coverageState,
            'canonical_mismatch' => $status->canonicalMismatch(),
            'last_crawled_at' => $status->lastCrawledAt?->toDateString(),
            'pending' => null,
        ];
    }

    /**
     * @return array{sessions: ?int, pending: ?string}
     */
    private function trafficBlock(Job $job, ?Site $site): array
    {
        if ($site === null || ! $this->traffic->connected($site)) {
            return ['sessions' => null, 'pending' => 'Connect GA4'];
        }

        $sessions = $this->traffic->sessions($site, $job->publicPath());

        return $sessions === null ? ['sessions' => null, 'pending' => 'Collecting'] : ['sessions' => $sessions, 'pending' => null];
    }
}
