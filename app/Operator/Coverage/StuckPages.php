<?php

namespace App\Operator\Coverage;

use App\Enums\IndexCoverageState;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;

/**
 * The stuck pages — published longer than the stuck window and still not indexed — each with the one
 * fact that decides the lever: how many other pages on the site link to it. Google's reason says what
 * it thinks of the page; the inbound-link count says whether it can even find or trust it. Together:
 *
 *  - never seen / discovered, 0 links  → link it (the market's link plan), then ping
 *  - never seen / discovered, linked   → ping IndexNow + resubmit the sitemap; wait for the crawl
 *  - crawled — not indexed, 0 links    → link it FIRST; a page nobody links to reads as thin
 *  - crawled — not indexed, linked     → regenerate with local grounding (jobs, reviews), then re-inspect
 *  - not yet inspected                 → re-check indexing (the daily sync has not reached it)
 *  - noindex / blocked                 → fix on WordPress; Launchpad cannot index past a noindex
 *  - inspection error                  → re-check indexing
 */
class StuckPages
{
    public const LINK = 'link';

    public const PING = 'ping';

    public const REGENERATE = 'regenerate';

    public const RECHECK = 'recheck';

    public const UNBLOCK = 'unblock';

    public function __construct(
        private readonly IndexWatchlist $watchlist,
        private readonly InternalLinkGraph $graph,
    ) {}

    /**
     * @return array{
     *     rows: list<array{content_id: string, title: string, url: ?string, kind: string, published_at: ?string, days_waiting: ?int, reason: string, inbound: int, indexnow_at: ?string, market_id: ?string, lever: string, action: string}>,
     *     by_lever: array<string, int>, stuck_days: int, markets_needing_links: list<string>
     * }
     */
    public function for(Site $site): array
    {
        $list = $this->watchlist->for($site);
        $stuckDays = $list['metrics']['stuck_days'];
        $graph = $this->graph->build($site);

        $rows = [];
        $byLever = [];
        $markets = [];
        foreach ($list['rows'] as $row) {
            if ($row['state'] === 'indexed' || ($row['days_waiting'] ?? 0) < $stuckDays) {
                continue;
            }
            $inbound = count($graph->inbound($row['content_id']));
            [$lever, $action] = $this->lever($row['verdict'], $inbound);
            $rows[] = [
                'content_id' => $row['content_id'],
                'title' => $row['title'],
                'url' => $row['url'],
                'kind' => $row['kind'],
                'published_at' => $row['published_at'],
                'days_waiting' => $row['days_waiting'],
                'reason' => $row['reason'] ?? IndexCoverageState::NotInspected->label(),
                'inbound' => $inbound,
                'indexnow_at' => $row['indexnow_at'],
                'market_id' => $row['market_id'],
                'lever' => $lever,
                'action' => $action,
            ];
            $byLever[$lever] = ($byLever[$lever] ?? 0) + 1;
            if ($lever === self::LINK && $row['market_id'] !== null) {
                $markets[$row['market_id']] = true;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['lever'], -($a['days_waiting'] ?? 0), $a['title']] <=> [$b['lever'], -($b['days_waiting'] ?? 0), $b['title']]);
        ksort($byLever);

        return [
            'rows' => $rows,
            'by_lever' => $byLever,
            'stuck_days' => $stuckDays,
            'markets_needing_links' => array_keys($markets),
        ];
    }

    /** @return array{0: string, 1: string} [lever, the sentence] */
    private function lever(?string $verdict, int $inbound): array
    {
        $state = $verdict === null ? IndexCoverageState::NotInspected : (IndexCoverageState::tryFrom($verdict) ?? IndexCoverageState::NotIndexedOther);

        return match (true) {
            $state === IndexCoverageState::NotInspected => [self::RECHECK, 'Not inspected yet — run "Re-check indexing" so Search Console reports on it'],
            $state === IndexCoverageState::Error => [self::RECHECK, 'Inspection errored — re-check indexing'],
            $state === IndexCoverageState::ExcludedBlocked => [self::UNBLOCK, 'Google reports noindex/blocked — fix on WordPress (robots, noindex, or a blocked path)'],
            $state === IndexCoverageState::CrawledNotIndexed && $inbound === 0 => [self::LINK, 'Crawled but judged not worth indexing, and nothing links to it — link it first (the market link plan), then re-inspect'],
            $state === IndexCoverageState::CrawledNotIndexed => [self::REGENERATE, 'Crawled, linked, still not indexed — regenerate with local grounding (jobs, reviews, neighbours), then re-inspect'],
            $inbound === 0 => [self::LINK, 'Google has not crawled it and nothing links to it — link it (the market link plan), then ping IndexNow'],
            default => [self::PING, 'Linked but not crawled yet — ping IndexNow and resubmit the sitemap, then wait for the crawl'],
        };
    }
}
