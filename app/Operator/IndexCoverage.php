<?php

namespace App\Operator;

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\JobStatus;
use App\Integrations\UrlInspection\IndexInspector;
use App\Integrations\UrlInspection\IndexStatus;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\Job;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\DiscoveredUrls;
use App\Support\PublicUrl;

/**
 * The per-tenant index-coverage audit — the honest answer to "does our marked index match what Google
 * actually holds?". For every published page AND post it runs a Google URL Inspection ({@see IndexInspector})
 * and tallies the real `coverageState`, so the operator sees "X of Y indexed", which URLs are only
 * "crawled — not indexed", and which are (correctly) excluded by redirect — rather than the impressions>0
 * proxy the cards use.
 *
 * `audit()` (live) drives the batched inspection + caches each result (so the cards then read the truth via
 * {@see IndexInspector::cached()}); `summary()` reads only what's already cached. Both degrade honestly:
 * with no grant / property, `connected` is false and nothing is fabricated; over the daily quota, the
 * remainder count as `not_inspected`.
 */
class IndexCoverage
{
    public function __construct(
        private readonly IndexInspector $inspector,
        private readonly ?DiscoveredUrls $discovered = null,
    ) {}

    /**
     * Run (or read-cache) an inspection for every published URL. `live=false` reads only cached results
     * (no API calls) — for a cheap render; `live=true` performs the batched, quota-guarded inspection.
     *
     * Three passes, in the order the budget should be spent: the pages Launchpad published, then published
     * job pages, then — when the all-known capture is on — the URLs Google has shown that are not ours
     * ({@see DiscoveredUrls}: legacy posts, archives). The legacy pass is last on purpose: a budget-capped
     * run must reach every submitted page before it spends a call on a page nobody wrote.
     *
     * @return array{
     *   connected: bool,
     *   total: int, inspected: int, indexed: int, not_inspected: int, discovered: int,
     *   by_state: array<string, int>,
     *   findings: list<array{content_id: string, kind: string, title: string, url: string, state: string, label: string, indexed: bool, coverage_state: string, canonical_mismatch: bool, google_canonical: ?string, inspected_at: ?string}>,
     * }
     */
    public function audit(Site $site, bool $live = true, ?float $liveBudgetSeconds = null): array
    {
        $connected = $this->inspector->connected($site);

        // A live inspection is one Google URL-Inspection call per URL (quota + latency bound), so a large
        // site can't inspect every URL inside a queue/job timeout. With a budget, we inspect live until it's
        // spent, then fall back to cached verdicts for the rest — repeated runs + the inspector's cache TTL
        // fill coverage over days. Null budget = inspect everything (the weekly console audit's behavior).
        $deadline = $liveBudgetSeconds !== null ? microtime(true) + $liveBudgetSeconds : null;

        $pages = Content::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')
            ->orderBy('published_at') // stable base tiebreak within an equal-freshness bucket
            // page_type is REQUIRED: PublicUrl::forContent reads it to canonicalize the home page to "/".
            // Omitting it left the home page inspected at "/home/" (a 301) → a permanent excluded_redirect.
            ->get(['id', 'kind', 'title', 'slug', 'page_type']);

        // Inspect UNINSPECTED pages first, then the STALEST verdicts — so the daily, budget-capped run
        // reaches the newest and most out-of-date URLs before it runs out, instead of always re-chewing
        // the oldest-published head (which, at a short cache TTL, starved the newest pages forever). One
        // grouped query for the freshness map (a content can carry >1 row after a slug change → max()).
        $freshness = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('content_id')
            ->groupBy('content_id')
            ->selectRaw('content_id, max(last_inspected_at) as last_inspected_at')
            ->pluck('last_inspected_at', 'content_id');

        $pages = $pages->sortBy(function (Content $content) use ($freshness): string {
            $last = $freshness[$content->id] ?? null;

            // '0…' (uninspected) sorts before '1{timestamp}' (inspected, oldest verdict first).
            return $last === null || $last === '' ? '0' : '1'.$last;
        })->values();

        $findings = [];
        $byState = [];
        $indexed = 0;
        $inspected = 0;

        $tally = function (array $finding) use (&$findings, &$byState, &$indexed, &$inspected): void {
            $findings[] = $finding;
            $byState[$finding['state']] = ($byState[$finding['state']] ?? 0) + 1;
            if ($finding['state'] !== IndexCoverageState::NotInspected->value) {
                $inspected++;
            }
            if ($finding['indexed']) {
                $indexed++;
            }
        };

        foreach ($pages as $content) {
            // Trailing-slash form (PublicUrl) so this inspects/caches the SAME URL the Live cards read —
            // the WordPress permalink, not the slash-less variant that 301-redirects to it.
            $url = PublicUrl::forContent($site->domain_url, $content);
            $status = ($connected && $url !== null) ? $this->resolve($site, $url, $live, $deadline) : null;
            $url ??= '/'.ltrim((string) $content->slug, '/');

            $tally($this->finding((string) $content->id, (string) ($content->kind->value ?? ''), (string) $content->title, $url, $status));
        }

        // Job Capture pages too — inspect + cache each published job's URL so the Published-Jobs cards can
        // read the real index verdict (via IndexInspector::cached()), the same way the content cards do.
        $jobs = Job::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('status', JobStatus::Published->value)
            ->with(['jobTypes', 'city'])
            ->get();

        foreach ($jobs as $job) {
            $url = $job->publicUrl($site->domain_url);
            $status = ($connected && $url !== null) ? $this->resolve($site, $url, $live, $deadline) : null;

            $tally($this->finding((string) $job->id, 'job', $job->publicTitle(), $url ?? $job->publicPath(), $status));
        }

        // The all-known capture: the URLs Google has shown that Launchpad did not publish, so the board's
        // "All known pages" panel is built from Google's verdicts on real legacy URLs — not inferred.
        // Same stalest-first order as the pages, keyed on the URL since there is no content to key on.
        $discoveredUrls = ($connected && $this->captureAllKnown()) ? $this->discoveredUrls($site) : [];
        $discovered = count($discoveredUrls);

        foreach ($discoveredUrls as $url) {
            $status = $this->resolve($site, $url, $live, $deadline);

            $tally($this->finding('', 'discovered', $url, $url, $status));
        }

        $total = count($findings);

        return [
            'connected' => $connected,
            'total' => $total,
            'inspected' => $inspected,
            'indexed' => $indexed,
            'not_inspected' => $total - $inspected,
            'discovered' => $discovered,
            'by_state' => $byState,
            'findings' => $findings,
        ];
    }

    /** Live (cache-first, quota-guarded) while the budget lasts, cached-only after — never null-on-principle. */
    private function resolve(Site $site, string $url, bool $live, ?float $deadline): ?IndexStatus
    {
        return $this->inspectLive($live, $deadline)
            ? $this->inspector->inspect($site, $url)
            : $this->inspector->cached($site, $url);
    }

    /**
     * One finding row. A null status is the honest "not inspected" (quota reached / never fetched) — never
     * a fabricated verdict.
     *
     * @return array{content_id: string, kind: string, title: string, url: string, state: string, label: string, indexed: bool, coverage_state: string, canonical_mismatch: bool, google_canonical: ?string, inspected_at: ?string}
     */
    private function finding(string $id, string $kind, string $title, string $url, ?IndexStatus $status): array
    {
        if ($status === null) {
            $state = IndexCoverageState::NotInspected;

            return [
                'content_id' => $id, 'kind' => $kind, 'title' => $title, 'url' => $url,
                'state' => $state->value, 'label' => $state->label(),
                'indexed' => false, 'coverage_state' => '', 'canonical_mismatch' => false, 'google_canonical' => null,
                'inspected_at' => null,
            ];
        }

        return [
            'content_id' => $id, 'kind' => $kind, 'title' => $title, 'url' => $url,
            'state' => $status->state->value, 'label' => $status->state->label(),
            'indexed' => $status->indexed(), 'coverage_state' => $status->coverageState,
            'canonical_mismatch' => $status->canonicalMismatch(), 'google_canonical' => $status->googleCanonical,
            'inspected_at' => $status->inspectedAt?->toIso8601String(),
        ];
    }

    private function captureAllKnown(): bool
    {
        return (bool) config('launchpad.indexing.all_known_capture', true);
    }

    /**
     * The legacy URLs in stalest-first order: never-inspected first, then by the oldest stored verdict —
     * the same rule the pages use, so a budget-capped run always reaches the URLs it knows least about.
     *
     * @return list<string>
     */
    private function discoveredUrls(Site $site): array
    {
        $urls = ($this->discovered ?? app(DiscoveredUrls::class))->urls($site);
        if ($urls === []) {
            return [];
        }

        $freshness = PageIndexState::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('origin', 'discovered')
            ->pluck('last_inspected_at', 'url_normalized');

        usort($urls, function (string $a, string $b) use ($freshness): int {
            $key = fn (string $url): string => ($last = $freshness[UrlNormalizer::url($url)] ?? null) === null || $last === '' ? '0' : '1'.$last;

            return strcmp($key($a), $key($b));
        });

        return $urls;
    }

    /** Whether to make a live inspection now: only when live mode is on and the time budget isn't spent. */
    private function inspectLive(bool $live, ?float $deadline): bool
    {
        return $live && ($deadline === null || microtime(true) < $deadline);
    }
}
