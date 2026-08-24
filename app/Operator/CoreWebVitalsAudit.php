<?php

namespace App\Operator;

use App\Enums\ContentStatus;
use App\Integrations\PageSpeed\PageSpeedProvider;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\PageVital;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;

/**
 * Measures a site's published pages through the PageSpeed Insights seam and upserts the durable
 * {@see PageVital} readings that feed the client "Site speed" card. Hardened like {@see IndexCoverage}:
 * each run is bounded by a wall-clock budget (one PSI call per URL is slow) and skips URLs whose stored
 * reading is still fresh — so the weekly sweep fills coverage over time without burning quota or a job
 * timeout. A clean no-op when the integration is disabled.
 */
class CoreWebVitalsAudit
{
    public function __construct(private readonly PageSpeedProvider $psi) {}

    /**
     * @return array{enabled: bool, total: int, measured: int, skipped_fresh: int, deferred: int}
     */
    public function audit(Site $site, ?float $budgetSeconds = null, ?int $freshnessDays = null): array
    {
        $total = 0;
        $measured = 0;
        $skippedFresh = 0;
        $deferred = 0;

        if (! $this->psi->enabled()) {
            return ['enabled' => false, 'total' => 0, 'measured' => 0, 'skipped_fresh' => 0, 'deferred' => 0];
        }

        $budget = $budgetSeconds ?? (float) config('launchpad.vitals.budget_seconds', 240);
        $freshness = $freshnessDays ?? (int) config('launchpad.vitals.freshness_days', 7);
        $deadline = microtime(true) + $budget;
        $freshBefore = Carbon::now()->subDays($freshness);

        $pages = Content::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('slug')
            ->get(['id', 'slug', 'title']);

        $existing = PageVital::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->get()
            ->keyBy(fn (PageVital $v): string => (string) $v->url_normalized);

        foreach ($pages as $content) {
            $url = PublicUrl::forContent($site->domain_url, $content);
            if ($url === null) {
                continue;
            }
            $total++;
            $normalized = UrlNormalizer::url($url);

            $prior = $existing->get($normalized);
            if ($prior !== null && $prior->measured_at !== null && $prior->measured_at->greaterThan($freshBefore)) {
                $skippedFresh++;

                continue;
            }

            if (microtime(true) >= $deadline) {
                $deferred++;   // budget spent — leave the prior reading; next run picks it up

                continue;
            }

            $vitals = $this->psi->measure($url);
            if ($vitals === null) {
                continue;   // API error / no usable data — keep any prior reading, don't overwrite with a blank
            }

            PageVital::withoutGlobalScopes()->updateOrCreate(
                ['site_id' => $site->id, 'url_normalized' => $normalized],
                [
                    'content_id' => $content->id,
                    'url' => $url,
                    'strategy' => $vitals->strategy,
                    'performance_score' => $vitals->performanceScore,
                    'lcp_ms' => $vitals->lcpMs,
                    'cls' => $vitals->cls,
                    'inp_ms' => $vitals->inpMs,
                    'measured_at' => Carbon::now(),
                ],
            );
            $measured++;
        }

        return ['enabled' => true, 'total' => $total, 'measured' => $measured, 'skipped_fresh' => $skippedFresh, 'deferred' => $deferred];
    }
}
