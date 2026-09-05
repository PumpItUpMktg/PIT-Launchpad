<?php

namespace App\Operator;

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Enums\JobStatus;
use App\Integrations\UrlInspection\IndexInspector;
use App\Models\Content;
use App\Models\Job;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
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
    public function __construct(private readonly IndexInspector $inspector) {}

    /**
     * Run (or read-cache) an inspection for every published URL. `live=false` reads only cached results
     * (no API calls) — for a cheap render; `live=true` performs the batched, quota-guarded inspection.
     *
     * @return array{
     *   connected: bool,
     *   total: int, inspected: int, indexed: int, not_inspected: int,
     *   by_state: array<string, int>,
     *   findings: list<array{content_id: string, kind: string, title: string, url: string, state: string, label: string, indexed: bool, coverage_state: string, canonical_mismatch: bool, google_canonical: ?string}>,
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

        foreach ($pages as $content) {
            // Trailing-slash form (PublicUrl) so this inspects/caches the SAME URL the Live cards read —
            // the WordPress permalink, not the slash-less variant that 301-redirects to it.
            $url = PublicUrl::forContent($site->domain_url, $content);
            $status = ($connected && $url !== null)
                ? ($this->inspectLive($live, $deadline) ? $this->inspector->inspect($site, $url) : $this->inspector->cached($site, $url))
                : null;
            $url ??= '/'.ltrim((string) $content->slug, '/');

            if ($status === null) {
                $state = IndexCoverageState::NotInspected;
                $byState[$state->value] = ($byState[$state->value] ?? 0) + 1;
                $findings[] = [
                    'content_id' => (string) $content->id, 'kind' => (string) ($content->kind->value ?? ''),
                    'title' => (string) $content->title, 'url' => $url, 'state' => $state->value, 'label' => $state->label(),
                    'indexed' => false, 'coverage_state' => '', 'canonical_mismatch' => false, 'google_canonical' => null,
                ];

                continue;
            }

            $inspected++;
            if ($status->indexed()) {
                $indexed++;
            }
            $byState[$status->state->value] = ($byState[$status->state->value] ?? 0) + 1;

            $findings[] = [
                'content_id' => (string) $content->id,
                'kind' => (string) ($content->kind->value ?? ''),
                'title' => (string) $content->title,
                'url' => $url,
                'state' => $status->state->value,
                'label' => $status->state->label(),
                'indexed' => $status->indexed(),
                'coverage_state' => $status->coverageState,
                'canonical_mismatch' => $status->canonicalMismatch(),
                'google_canonical' => $status->googleCanonical,
            ];
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
            $status = ($connected && $url !== null)
                ? ($this->inspectLive($live, $deadline) ? $this->inspector->inspect($site, $url) : $this->inspector->cached($site, $url))
                : null;
            $displayUrl = $url ?? $job->publicPath();

            if ($status === null) {
                $state = IndexCoverageState::NotInspected;
                $byState[$state->value] = ($byState[$state->value] ?? 0) + 1;
                $findings[] = [
                    'content_id' => (string) $job->id, 'kind' => 'job',
                    'title' => $job->publicTitle(), 'url' => $displayUrl, 'state' => $state->value, 'label' => $state->label(),
                    'indexed' => false, 'coverage_state' => '', 'canonical_mismatch' => false, 'google_canonical' => null,
                ];

                continue;
            }

            $inspected++;
            if ($status->indexed()) {
                $indexed++;
            }
            $byState[$status->state->value] = ($byState[$status->state->value] ?? 0) + 1;

            $findings[] = [
                'content_id' => (string) $job->id, 'kind' => 'job',
                'title' => $job->publicTitle(), 'url' => $displayUrl,
                'state' => $status->state->value, 'label' => $status->state->label(),
                'indexed' => $status->indexed(), 'coverage_state' => $status->coverageState,
                'canonical_mismatch' => $status->canonicalMismatch(), 'google_canonical' => $status->googleCanonical,
            ];
        }

        $total = count($findings);

        return [
            'connected' => $connected,
            'total' => $total,
            'inspected' => $inspected,
            'indexed' => $indexed,
            'not_inspected' => $total - $inspected,
            'by_state' => $byState,
            'findings' => $findings,
        ];
    }

    /** Whether to make a live inspection now: only when live mode is on and the time budget isn't spent. */
    private function inspectLive(bool $live, ?float $deadline): bool
    {
        return $live && ($deadline === null || microtime(true) < $deadline);
    }
}
