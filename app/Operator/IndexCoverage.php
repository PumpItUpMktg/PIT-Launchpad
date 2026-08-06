<?php

namespace App\Operator;

use App\Enums\ContentStatus;
use App\Enums\IndexCoverageState;
use App\Integrations\UrlInspection\IndexInspector;
use App\Models\Content;
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
    public function audit(Site $site, bool $live = true): array
    {
        $connected = $this->inspector->connected($site);

        $pages = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')
            ->orderBy('kind')
            ->orderBy('published_at')
            ->get(['id', 'kind', 'title', 'slug']);

        $findings = [];
        $byState = [];
        $indexed = 0;
        $inspected = 0;

        foreach ($pages as $content) {
            // Trailing-slash form (PublicUrl) so this inspects/caches the SAME URL the Live cards read —
            // the WordPress permalink, not the slash-less variant that 301-redirects to it.
            $url = PublicUrl::for($site->domain_url, $content->slug);
            $status = ($connected && $url !== null)
                ? ($live ? $this->inspector->inspect($site, $url) : $this->inspector->cached($site, $url))
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
}
