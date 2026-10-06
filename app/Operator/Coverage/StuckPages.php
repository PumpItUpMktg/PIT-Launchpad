<?php

namespace App\Operator\Coverage;

use App\Enums\ContentKind;
use App\Enums\IndexCoverageState;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
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
 *
 * Each row also carries a plain recommendation for the operator — WAIT (a process lever: link, ping,
 * re-check, unblock), REWORK (Google saw the page and passed; the content has to change), or DROP (a blog
 * post Google crawled and declined that has never earned a single impression: a thin or duplicate news
 * item — take it down rather than polish it). A core / location / service page is never "drop".
 */
class StuckPages
{
    public const LINK = 'link';

    public const PING = 'ping';

    public const REGENERATE = 'regenerate';

    public const RECHECK = 'recheck';

    public const UNBLOCK = 'unblock';

    /** A crawled-and-declined blog post with no impression ever: not worth reworking. */
    public const DROP = 'drop';

    public const WAIT = 'wait';

    /** The page's push never landed, drifted, or fell out of the sitemap: re-push it. */
    public const REPUSH = 'repush';

    /** A post that duplicates a live post: merge it into the stronger one (redirect + take-down). */
    public const MERGE = 'merge';

    /** 30–60 days, not crawled, linked and listed: ask Google — request indexing by hand, resubmit the sitemap. */
    public const REQUEST = 'request';

    /** 60–90 days, not crawled: decide — a stronger inbound link, or fold a small town into its hub. */
    public const DECIDE = 'decide';

    /** 90+ days, not crawled: Google has judged the site's crawl budget — fewer, better pages first. */
    public const BUDGET = 'budget';

    public const STAGE_ASK = 'ask';

    public const STAGE_DECIDE = 'decide';

    public const STAGE_BUDGET = 'budget';

    public const REWORK = 'rework';

    public function __construct(
        private readonly IndexWatchlist $watchlist,
        private readonly InternalLinkGraph $graph,
        private readonly PageImpressions $impressions,
        private readonly Reachability $reachability,
        private readonly ReworkCandidates $rework,
    ) {}

    /**
     * @return array{
     *     rows: list<array{content_id: string, title: string, url: ?string, kind: string, published_at: ?string, days_waiting: ?int, reason: string, inbound: int, impressions_ever: bool, indexnow_at: ?string, market_id: ?string, lever: string, action: string, recommendation: string, is_post: bool}>,
     *     by_lever: array<string, int>, stuck_days: int, markets_needing_links: list<string>
     * }
     */
    public function for(Site $site): array
    {
        $list = $this->watchlist->for($site);
        $stuckDays = $list['metrics']['stuck_days'];
        $graph = $this->graph->build($site);

        $stuck = array_values(array_filter($list['rows'], fn (array $row): bool => $row['state'] !== 'indexed' && ($row['days_waiting'] ?? 0) >= $stuckDays));
        $pages = Content::query()->withoutGlobalScope(SiteScope::class)->whereKey(array_column($stuck, 'content_id'))->get();
        $everSeen = $this->impressions->ever($site, $pages);
        $kinds = $pages->mapWithKeys(fn (Content $c): array => [(string) $c->id => $c->kind])->all();

        // A page UNKNOWN to Google is never a content question: the reachability check says whether the
        // push landed, the URL agrees, the sitemap lists it, Search Console has read the sitemap, and an
        // indexed page links to it — one read for all of them.
        $reach = [];
        if (array_filter($stuck, fn (array $r): bool => $r['verdict'] === IndexCoverageState::Unknown->value) !== []) {
            foreach ($this->reachability->for($site)['pages'] as $p) {
                $reach[$p['content_id']] = $p;
            }
        }

        // A page Google CRAWLED and declined, past the rework window: the three-way check (merge a duplicate
        // post, drop an off-topic post, rework everything else — a town page always) replaces the generic lever.
        $rework = [];
        if (array_filter($stuck, fn (array $r): bool => $r['verdict'] === IndexCoverageState::CrawledNotIndexed->value && ($r['days_waiting'] ?? 0) >= ReworkCandidates::reworkDays()) !== []) {
            foreach ($this->rework->for($site)['rows'] as $r) {
                $rework[$r['content_id']] = $r;
            }
        }

        $rows = [];
        $byLever = [];
        $markets = [];
        foreach ($stuck as $row) {
            $inbound = count($graph->inbound($row['content_id']));
            $isPost = ($kinds[$row['content_id']] ?? null) === ContentKind::Post;
            $seen = isset($everSeen[$row['content_id']]);
            $stage = self::stage((int) ($row['days_waiting'] ?? 0));
            [$lever, $action] = match (true) {
                isset($reach[$row['content_id']]) => self::reachabilityLever($reach[$row['content_id']]),
                isset($rework[$row['content_id']]) => self::reworkLever($rework[$row['content_id']]),
                default => $this->lever($row['verdict'], $inbound, $isPost, $seen, $stage),
            };
            $rows[] = [
                'content_id' => $row['content_id'],
                'title' => $row['title'],
                'url' => $row['url'],
                'kind' => $row['kind'],
                'published_at' => $row['published_at'],
                'days_waiting' => $row['days_waiting'],
                'reason' => $row['reason'] ?? IndexCoverageState::NotInspected->label(),
                'inbound' => $inbound,
                'impressions_ever' => $seen,
                'indexnow_at' => $row['indexnow_at'],
                'market_id' => $row['market_id'],
                'lever' => $lever,
                'action' => $action,
                'recommendation' => match ($lever) {
                    self::DROP => self::DROP,
                    self::REGENERATE, self::MERGE => self::REWORK,
                    default => self::WAIT,
                },
                'is_post' => $isPost,
                'reachability' => $reach[$row['content_id']] ?? null,
                'rework' => $rework[$row['content_id']] ?? null,
                'stage' => $stage,
                'stage_label' => self::stageLabel($stage),
                'inspect_url' => self::inspectUrl($site, $row['url']),
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

    /**
     * The lever for a page unknown to Google, from its reachability verdict.
     *
     * @param  array{verdict: string, action: string}  $r
     * @return array{0: string, 1: string}
     */
    private static function reachabilityLever(array $r): array
    {
        return match ($r['verdict']) {
            Reachability::MISSING_ON_SITE, Reachability::URL_MISMATCH, Reachability::NOT_IN_SITEMAP => [self::REPUSH, $r['action']],
            Reachability::SITEMAP_STALE => [self::PING, $r['action']],
            Reachability::ORPHAN => [self::LINK, $r['action']],
            default => [self::PING, $r['action']],
        };
    }

    /**
     * The lever for a crawled-and-declined page past the rework window, from its three-way verdict.
     *
     * @param  array{verdict: string, reason: string, is_town: bool}  $r
     * @return array{0: string, 1: string}
     */
    private static function reworkLever(array $r): array
    {
        return match ($r['verdict']) {
            ReworkCandidates::DUPLICATE => [self::MERGE, $r['reason']],
            ReworkCandidates::OFF_TOPIC => [self::DROP, $r['reason']],
            default => [self::REGENERATE, $r['reason']],
        };
    }

    /** Where a not-crawled page sits on the 30 / 60 / 90-day timeline. */
    public static function stage(int $daysWaiting): string
    {
        return match (true) {
            $daysWaiting >= self::budgetDays() => self::STAGE_BUDGET,
            $daysWaiting >= self::decideDays() => self::STAGE_DECIDE,
            default => self::STAGE_ASK,
        };
    }

    public static function stageLabel(string $stage): string
    {
        return match ($stage) {
            self::STAGE_BUDGET => sprintf('%d+ days — crawl budget', self::budgetDays()),
            self::STAGE_DECIDE => sprintf('%d–%d days — decide', self::decideDays(), self::budgetDays()),
            default => sprintf('%d–%d days — ask Google', max(1, (int) config('launchpad.indexing.stuck_days', 30)), self::decideDays()),
        };
    }

    public static function decideDays(): int
    {
        return max(1, (int) config('launchpad.indexing.timeline.decide_days', 60));
    }

    public static function budgetDays(): int
    {
        return max(self::decideDays() + 1, (int) config('launchpad.indexing.timeline.budget_days', 90));
    }

    /** The Search Console URL-inspection link for a page (the manual "request indexing"), when the property is known. */
    public static function inspectUrl(Site $site, ?string $url): ?string
    {
        $property = is_string($site->gsc_property) ? trim($site->gsc_property) : '';
        if ($property === '' || $url === null || $url === '') {
            return null;
        }

        return 'https://search.google.com/search-console/inspect?resource_id='.rawurlencode($property).'&id='.rawurlencode($url);
    }

    /** @return array{0: string, 1: string} [lever, the sentence] */
    private function lever(?string $verdict, int $inbound, bool $isPost = false, bool $impressionsEver = false, string $stage = self::STAGE_ASK): array
    {
        $state = $verdict === null ? IndexCoverageState::NotInspected : (IndexCoverageState::tryFrom($verdict) ?? IndexCoverageState::NotIndexedOther);

        return match (true) {
            $state === IndexCoverageState::NotInspected => [self::RECHECK, 'Not inspected yet — run "Re-check indexing" so Search Console reports on it'],
            $state === IndexCoverageState::Error => [self::RECHECK, 'Inspection errored — re-check indexing'],
            $state === IndexCoverageState::ExcludedBlocked => [self::UNBLOCK, 'Google reports noindex/blocked — fix on WordPress (robots, noindex, or a blocked path)'],
            // A post Google crawled and declined that has never earned an impression is thin or duplicate:
            // linking it will not change Google's verdict — drop it (checked before the link lever, which is
            // the right first move for a PAGE).
            $state === IndexCoverageState::CrawledNotIndexed && $isPost && ! $impressionsEver => [self::DROP, 'Google crawled it, declined to index it, and it has never earned a single impression — a thin or duplicate post. Take it down rather than rework it; the pillar page carries the topic'],
            $state === IndexCoverageState::CrawledNotIndexed && $inbound === 0 => [self::LINK, 'Crawled but judged not worth indexing, and nothing links to it — link it first (the market link plan), then re-inspect'],
            $state === IndexCoverageState::CrawledNotIndexed => [self::REGENERATE, 'Crawled, linked, still not indexed — regenerate with local grounding (jobs, reviews, neighbours), then re-inspect'],
            // Not crawled: the 30 / 60 / 90-day timeline. Nothing here is a content lever — Google has not read the page.
            $stage === self::STAGE_BUDGET => [self::BUDGET, sprintf('%d+ days without a crawl — Google has judged the site\'s crawl budget, not this page. Fewer, better pages first: prune off-topic posts and rework the thin ones (Rework candidates); this page gets its turn as the site earns more crawl', self::budgetDays())],
            $stage === self::STAGE_DECIDE && $isPost => [self::DECIDE, sprintf('%d+ days without a crawl — decide: give it a link from a ranking post in its silo, or drop it if it is off the site\'s topics', self::decideDays())],
            $stage === self::STAGE_DECIDE => [self::DECIDE, sprintf('%d+ days without a crawl — decide: keep it with a stronger inbound link from a ranking page, or fold a small town into its hub. A town page is never dropped', self::decideDays())],
            $inbound === 0 => [self::LINK, 'Google has not crawled it and nothing links to it — link it from a ranking page (the index booster / market link plan), then resubmit the sitemap'],
            default => [self::REQUEST, 'Linked and in the sitemap, not crawled yet — ask Google: request indexing for it in Search Console (the link below), and resubmit the sitemap. Do not rewrite it: Google has not read it'],
        };
    }
}
