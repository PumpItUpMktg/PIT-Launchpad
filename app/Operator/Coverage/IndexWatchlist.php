<?php

namespace App\Operator\Coverage;

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Integrations\UrlInspection\IndexInspector;
use App\Models\Content;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operate\ContentCard;
use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The Indexing board's watchlist: every published page that is NOT yet in the index, with the date it was
 * published, so the operator can see what is waiting and for how long — plus, for a few days after it
 * lands, the page as a green "indexed" row before it falls off.
 *
 * Three states, the same verdict rule every surface uses ({@see ContentCard::resolveIndex}):
 *  - `published`  — Launchpad published it; Search Console has not inspected it yet. Plain.
 *  - `inspected`  — inspected, not indexed (the verdict says why). Amber.
 *  - `indexed`    — a PASS verdict OR impressions in the window. Green, and listed only while the index
 *                   date is within `launchpad.indexing.watch_days`; older than that it is simply indexed
 *                   and belongs on the page cards, not here.
 *
 * The index date is the earlier of the first PASS ({@see PageIndexState::$indexed_at}) and the first
 * impression ({@see PageImpressions::firstSeen()}) — whichever proved it first.
 *
 * Order: the waiting pages first, oldest published at the top (the ones stuck longest), then the landed
 * pages newest-indexed first.
 */
class IndexWatchlist
{
    public function __construct(
        private readonly PageImpressions $impressions,
        private readonly IndexInspector $inspector,
    ) {}

    /**
     * `readiness` says whether any of this can move: `connected` is the Search Console connection (a
     * Google grant + a property on the site — without it nothing here is ever inspected); `test_domain`
     * flags a build/staging host Google will never index ({@see config('launchpad.indexing.test_domain_suffixes')}).
     *
     * @return array{
     *     rows: list<array{content_id: string, title: string, url: ?string, kind: string, published_at: ?string, inspected_at: ?string, indexed_at: ?string, state: string, reason: ?string, verdict: ?string, days_waiting: ?int, market_id: ?string, indexnow_at: ?string}>,
     *     waiting: int, inspected: int, landed: int, watch_days: int,
     *     readiness: array{connected: bool, test_domain: bool, host: ?string},
     *     metrics: array{published_week: int, indexed_week: int, not_indexed: int, stuck: int, stuck_days: int}
     * }
     */
    /** The sortable columns, in the order the header shows them. */
    public const SORTS = ['published', 'inspected', 'status', 'indexed'];

    /**
     * Verdicts that are Google honouring something WE set — a redirect or a canonical — not a page waiting
     * to be indexed. Never on the list, never in the not-indexed or stuck counts (the same set the coverage
     * panels count as "Excluded (correct)").
     */
    public const EXCLUDED = [IndexCoverageState::ExcludedRedirect->value, IndexCoverageState::ExcludedCanonical->value];

    /**
     * @param  string  $sort  one of {@see SORTS}: `status` (waiting → inspected → landed, then oldest published),
     *                        `published`, `inspected` or `indexed` (a page with no such date sorts last)
     * @param  string  $dir  `asc` | `desc`
     */
    public function for(?Site $site, string $sort = 'status', string $dir = 'asc'): array
    {
        $watchDays = max(1, (int) config('launchpad.indexing.watch_days', 5));
        if ($site === null) {
            return [
                'rows' => [], 'waiting' => 0, 'inspected' => 0, 'landed' => 0, 'watch_days' => $watchDays,
                'readiness' => ['connected' => false, 'test_domain' => false, 'host' => null],
                'metrics' => ['published_week' => 0, 'indexed_week' => 0, 'not_indexed' => 0, 'stuck' => 0, 'stuck_days' => (int) config('launchpad.indexing.stuck_days', 10)],
            ];
        }

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->get(['id', 'title', 'slug', 'kind', 'page_type', 'published_at', 'parent_location_id', 'indexnow_submitted_at']);

        $verdicts = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('content_id')
            ->get(['content_id', 'index_verdict', 'last_inspected_at', 'indexed_at'])
            ->keyBy(fn (PageIndexState $r): string => (string) $r->content_id);

        $recent = $this->impressions->recent($site, $pages);
        $firstSeen = $this->impressions->firstSeen($site, $pages);

        $cutoff = Carbon::now()->subDays($watchDays)->startOfDay();
        $today = Carbon::now()->startOfDay();

        $waiting = [];
        $landed = [];
        /** @var array<string, Carbon> $indexedAtById every indexed page's index date (the metrics count past the watch window) */
        $indexedAtById = [];
        /** @var array<string, true> $excludedIds pages Google correctly excludes (redirect / canonical) — not waiting */
        $excludedIds = [];
        foreach ($pages as $page) {
            $id = (string) $page->id;
            /** @var PageIndexState|null $verdict */
            $verdict = $verdicts->get($id);
            $inspected = $verdict !== null && $verdict->last_inspected_at !== null;
            $pass = $verdict !== null && $verdict->index_verdict === 'PASS';
            $inGoogle = isset($recent[$id]);

            $published = $page->published_at instanceof Carbon ? $page->published_at : null;
            $row = [
                'content_id' => $id,
                'title' => trim((string) $page->title),
                'url' => PublicUrl::forContent($site->domain_url, $page),
                // A post has no page type — it is just its kind.
                'kind' => $page->page_type->value ?? $page->kind->value,
                'published_at' => $published?->toDateString(),
                'inspected_at' => $inspected ? $verdict->last_inspected_at->toDateString() : null,
                'indexed_at' => null,
                'state' => 'published',
                'reason' => null,
                'verdict' => $inspected ? (string) $verdict->index_verdict : null,
                'days_waiting' => $published === null ? null : (int) $published->copy()->startOfDay()->diffInDays($today),
                'market_id' => $page->parent_location_id === null ? null : (string) $page->parent_location_id,
                'indexnow_at' => $page->indexnow_submitted_at instanceof Carbon ? $page->indexnow_submitted_at->toDateString() : null,
            ];

            if ($pass || $inGoogle) {
                $indexedAt = $this->indexDate($pass ? $verdict->indexed_at : null, $firstSeen[$id] ?? null);
                // An indexed page with no date (a PASS row from before the stamp existed) is long indexed.
                $indexedAtById[$id] = $indexedAt ?? Carbon::createFromTimestamp(0);
                // No date at all reads as long indexed — it has nothing to show on a "just landed" list.
                if ($indexedAt === null || $indexedAt->lessThan($cutoff)) {
                    continue;
                }
                $row['state'] = 'indexed';
                $row['indexed_at'] = $indexedAt->toDateString();
                $row['days_waiting'] = $published === null ? null : (int) $published->copy()->startOfDay()->diffInDays($indexedAt->copy()->startOfDay());
                $landed[] = $row;

                continue;
            }

            if ($inspected && in_array((string) $verdict->index_verdict, self::EXCLUDED, true)) {
                $excludedIds[$id] = true;

                continue; // a correct exclusion is not a page waiting on Google
            }

            if ($inspected) {
                $row['state'] = 'inspected';
                $row['reason'] = IndexCoverageState::tryFrom((string) $verdict->index_verdict)?->label() ?? (string) $verdict->index_verdict;
            }
            $waiting[] = $row;
        }

        $rows = [...$waiting, ...$landed];
        usort($rows, $this->comparator(in_array($sort, self::SORTS, true) ? $sort : 'status', $dir === 'desc'));

        return [
            'rows' => $rows,
            'waiting' => count(array_filter($waiting, fn (array $r): bool => $r['state'] === 'published')),
            'inspected' => count(array_filter($waiting, fn (array $r): bool => $r['state'] === 'inspected')),
            'landed' => count($landed),
            'watch_days' => $watchDays,
            'readiness' => $this->readiness($site),
            'metrics' => $this->metrics($pages, $indexedAtById, $excludedIds, $today),
        ];
    }

    /**
     * The four numbers above the list, all from the same rows and the same index-date rule:
     *  - published_week: pages published in the last 7 days;
     *  - indexed_week:   pages whose index date is in the last 7 days (whatever their publish date);
     *  - not_indexed:    every published page not in the index today;
     *  - stuck:          of those, published more than `stuck_days` (10) days ago — the ones to act on.
     *
     * @param  Collection<int, Content>  $pages
     * @param  array<string, Carbon>  $indexedAtById  content id => index date, for every indexed page
     * @param  array<string, true>  $excludedIds  pages Google correctly excludes — neither indexed nor waiting
     * @return array{published_week: int, indexed_week: int, not_indexed: int, stuck: int, stuck_days: int}
     */
    private function metrics(Collection $pages, array $indexedAtById, array $excludedIds, Carbon $today): array
    {
        $stuckDays = max(1, (int) config('launchpad.indexing.stuck_days', 10));
        $weekAgo = $today->copy()->subDays(7);
        $stuckBefore = $today->copy()->subDays($stuckDays);

        $publishedWeek = 0;
        $indexedWeek = 0;
        $notIndexed = 0;
        $stuck = 0;
        foreach ($pages as $page) {
            $id = (string) $page->id;
            $published = $page->published_at instanceof Carbon ? $page->published_at->copy()->startOfDay() : null;
            if ($published !== null && $published->greaterThanOrEqualTo($weekAgo)) {
                $publishedWeek++;
            }
            if (isset($indexedAtById[$id])) {
                if ($indexedAtById[$id]->greaterThanOrEqualTo($weekAgo)) {
                    $indexedWeek++;
                }

                continue;
            }
            if (isset($excludedIds[$id])) {
                continue; // correctly excluded — not waiting, not stuck
            }
            $notIndexed++;
            if ($published !== null && $published->lessThanOrEqualTo($stuckBefore)) {
                $stuck++;
            }
        }

        return [
            'published_week' => $publishedWeek,
            'indexed_week' => $indexedWeek,
            'not_indexed' => $notIndexed,
            'stuck' => $stuck,
            'stuck_days' => $stuckDays,
        ];
    }

    /**
     * Whether the watchlist can ever move for this site: Search Console connected, and a real domain.
     *
     * @return array{connected: bool, test_domain: bool, host: ?string}
     */
    public function readiness(Site $site): array
    {
        $host = strtolower((string) parse_url((string) $site->domain_url, PHP_URL_HOST));
        $host = $host !== '' ? $host : null;

        $test = false;
        foreach ((array) config('launchpad.indexing.test_domain_suffixes', []) as $suffix) {
            $suffix = strtolower(trim((string) $suffix));
            if ($host !== null && $suffix !== '' && ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix) || str_ends_with($host, '.'.ltrim($suffix, '.')))) {
                $test = true;
                break;
            }
        }

        return [
            'connected' => $this->inspector->connected($site),
            'test_domain' => $test,
            'host' => $host,
        ];
    }

    /**
     * The row order for one sort column. A date column sorts by that date with the undated rows LAST in
     * either direction (an absent date is not "earliest", it is "not yet"); `status` walks the states in
     * roll-out order (waiting → inspected → landed) with the oldest published first inside each; the
     * title is the final tiebreak so the order is stable across refreshes.
     *
     * @return callable(array<string, mixed>, array<string, mixed>): int
     */
    private function comparator(string $sort, bool $desc): callable
    {
        $rank = ['published' => 0, 'inspected' => 1, 'indexed' => 2];
        $flip = $desc ? -1 : 1;

        return function (array $a, array $b) use ($sort, $rank, $flip): int {
            if ($sort === 'status') {
                $cmp = ($rank[$a['state']] <=> $rank[$b['state']]) * $flip;

                return $cmp !== 0 ? $cmp : ([$a['published_at'] ?? '9999', $a['title']] <=> [$b['published_at'] ?? '9999', $b['title']]);
            }
            $key = $sort.'_at';
            $da = $a[$key];
            $db = $b[$key];
            if (($da === null) !== ($db === null)) {
                return $da === null ? 1 : -1; // undated last, whatever the direction
            }
            $cmp = (($da ?? '') <=> ($db ?? '')) * $flip;

            return $cmp !== 0 ? $cmp : ($a['title'] <=> $b['title']);
        };
    }

    /** The earlier of the first PASS and the first impression — whichever proved the page indexed first. */
    private function indexDate(?Carbon $firstPass, ?string $firstImpression): ?Carbon
    {
        $dates = array_filter([
            $firstPass?->copy()->startOfDay(),
            $firstImpression === null ? null : Carbon::parse($firstImpression)->startOfDay(),
        ]);
        if ($dates === []) {
            return null;
        }
        usort($dates, fn (Carbon $a, Carbon $b): int => $a <=> $b);

        return $dates[0];
    }
}
