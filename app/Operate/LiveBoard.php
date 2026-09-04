<?php

namespace App\Operate;

use App\Enums\BeatabilityLane;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Guided\LiveMetrics;
use App\Jobs\WarmLiveMetrics;
use App\Models\Content;
use App\Models\Market;
use App\Models\PageIndexState;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The unified Live read-model (Relay 3 · PR 5e). ONE dataset of every published Content — blog posts
 * and all page families — with a type filter, replacing the five per-family published boards (Blog,
 * Core, Service, Town, Storefront). "All" is the point: one place to answer "what's live and what's
 * wrong with it."
 *
 * Every displayed number comes from the CACHED {@see LiveMetrics::for()} block — no live vendor call
 * fires at render (a wall-clock budget defers the tail to "Refreshing…" and queues one warm pass, the
 * same guard the console board uses). The filter predicates read DURABLE state directly
 * (`page_index_states`, `position_snapshots`) so "Not indexed" / "Not ranking" are exact regardless of
 * the metrics budget.
 *
 * Type buckets (mutually exclusive; Storefront hubs are Location pages, so they live under Town):
 *  - **blog**    = `kind = post`
 *  - **core**    = `kind = page`, page_type ∈ {Home, Utility, Hub}
 *  - **service** = `kind = page`, page_type = Service
 *  - **town**    = `kind = page`, page_type = Location  (storefront hubs + towns + orphans)
 */
class LiveBoard
{
    private const BUDGET_SECONDS = 6.0;

    private float $renderStart = 0.0;

    private bool $warmDispatched = false;

    public function __construct(private readonly LiveMetrics $metrics) {}

    /** The type each tab counts — a live count beside every selector. @return array<string, int> */
    public function counts(Site $site): array
    {
        $counts = ['all' => 0, 'blog' => 0, 'core' => 0, 'service' => 0, 'town' => 0];

        foreach ($this->publishedQuery($site)->get(['kind', 'page_type']) as $c) {
            $counts['all']++;
            $counts[$this->bucket($c)]++;
        }

        return $counts;
    }

    /** Market options for the filter (this site's markets), value => label. @return array<string, string> */
    public function markets(Site $site): array
    {
        return Market::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The flat card list for the active tab + filters.
     *
     * @param  array{search?: string, market?: ?string, not_indexed?: bool, not_ranking?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(Site $site, string $tab = 'all', array $filters = []): array
    {
        $content = $this->publishedQuery($site)
            ->with(['site', 'targetKeyword'])
            ->orderByDesc('published_at')->orderByDesc('updated_at')
            ->get();

        if ($content->isEmpty()) {
            return [];
        }

        // Durable filter signals, batch-loaded (no per-row query): which content Google holds, and which
        // target keywords have ever ranked organically.
        $indexedIds = PageIndexState::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('index_verdict', 'PASS')
            ->pluck('content_id')->filter()->flip();
        $targetKeywordIds = $content->pluck('target_keyword_id')->filter()->unique()->values();
        $rankedKeywordIds = $targetKeywordIds->isEmpty()
            ? collect()
            : PositionSnapshot::query()
                ->whereIn('keyword_id', $targetKeywordIds->all())
                ->where('lane', BeatabilityLane::Organic->value)
                ->whereNotNull('rank')
                ->distinct()->pluck('keyword_id')->flip();

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $market = $filters['market'] ?? null;
        $notIndexed = (bool) ($filters['not_indexed'] ?? false);
        $notRanking = (bool) ($filters['not_ranking'] ?? false);

        $selected = $content
            ->filter(fn (Content $c): bool => $tab === 'all' || $this->bucket($c) === $tab)
            ->filter(fn (Content $c): bool => $search === ''
                || str_contains(mb_strtolower((string) $c->title), $search)
                || str_contains(mb_strtolower((string) $c->targetKeyword?->query), $search))
            ->filter(fn (Content $c): bool => $market === null || $market === '' || (string) $c->market_id === $market)
            ->filter(fn (Content $c): bool => ! $notIndexed || ! $indexedIds->has((string) $c->id))
            ->filter(fn (Content $c): bool => ! $notRanking
                || $c->target_keyword_id === null || ! $rankedKeywordIds->has((string) $c->target_keyword_id))
            ->values();

        // Metrics budget clock starts after the filtering + batch loads (setup never steals the budget).
        $this->renderStart = microtime(true);
        $this->warmDispatched = false;

        return $selected->map(fn (Content $c): array => $this->card($c))->all();
    }

    /** @return Builder<Content> */
    private function publishedQuery(Site $site): Builder
    {
        return Content::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value);
    }

    private function bucket(Content $c): string
    {
        if ($c->kind === ContentKind::Post) {
            return 'blog';
        }
        if ($c->page_type === PageType::Service) {
            return 'service';
        }
        if ($c->page_type === PageType::Location) {
            return 'town';
        }

        return 'core'; // Home / Utility / Hub, and any unset page_type
    }

    /** @return array<string, mixed> */
    private function card(Content $c): array
    {
        $domain = $c->site?->domain_url;
        $m = $this->metricsFor($c);

        $index = is_array($m['index'] ?? null) ? $m['index'] : [];
        $gsc = is_array($m['gsc'] ?? null) ? $m['gsc'] : [];
        $position = is_array($m['position'] ?? null) ? $m['position'] : [];
        $indexed = ($index['indexed'] ?? false) || ($gsc['in_google'] ?? false);
        $rank = $position['rank'] ?? null;

        return [
            'id' => (string) $c->id,
            'type' => $this->bucket($c),
            'type_label' => ['blog' => 'Blog', 'core' => 'Core', 'service' => 'Service', 'town' => 'Town'][$this->bucket($c)],
            'title' => (string) $c->title,
            'url' => PublicUrl::forContent($domain, $c),
            'wp_url' => $c->wp_post_id !== null && $domain !== null && $domain !== ''
                ? rtrim($domain, '/').'/wp-admin/post.php?post='.$c->wp_post_id.'&action=edit'
                : null,
            'locked' => (bool) $c->locked,
            'published_at' => $c->published_at?->toDateString(),
            // Flags row.
            'indexed' => (bool) $indexed,
            'in_bing' => (bool) (is_array($m['bing'] ?? null) ? ($m['bing']['in_bing'] ?? false) : false),
            'page_one' => $rank !== null && (int) $rank <= 10,
            'problem' => ! $indexed && ! empty($index['label']) ? (string) $index['label'] : null,
            // Tracking numbers (all from the cached LiveMetrics block).
            'rank' => $rank !== null ? (int) $rank : null,
            'delta' => $position['delta'] ?? null,
            'impressions' => $gsc['impressions'] ?? null,
            'clicks' => $gsc['clicks'] ?? null,
            'sessions' => is_array($m['traffic'] ?? null) ? ($m['traffic']['sessions'] ?? null) : null,
            'keyword' => $m['keyword'] ?? null,
            'pending' => ($position['pending'] ?? null) !== null,
        ];
    }

    /**
     * The tracking block for one card, render-safe: within the wall-clock budget it reads live GSC/Bing +
     * the warmed GA4 cache (never a live GA4 call); once the budget is spent the tail renders deferred and a
     * single warm pass is queued so the next load is complete.
     *
     * @return array<string, mixed>
     */
    private function metricsFor(Content $c): array
    {
        if ((microtime(true) - $this->renderStart) > (float) config('launchpad.published_metrics_budget_seconds', self::BUDGET_SECONDS)) {
            if (! $this->warmDispatched) {
                $this->warmDispatched = true;
                $lock = Cache::lock('warm-live-metrics:'.$c->site_id, 120);
                if ($lock->get()) {
                    WarmLiveMetrics::dispatch((string) $c->site_id); // TTL is the throttle; not released
                }
            }

            return $this->metrics->for($c, defer: true);
        }

        return $this->metrics->for($c, liveTraffic: false);
    }
}
