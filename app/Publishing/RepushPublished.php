<?php

namespace App\Publishing;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Jobs\PublishContent;
use App\Jobs\SubmitSitemap;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Bulk re-publish a site's already-live content — re-renders the engine-owned meta-blob
 * (canonical / og:url / schema) without touching the copy, so a stale value baked in at an earlier
 * publish (e.g. a canonical pointing at the old staging host after a domain move) is corrected.
 * Idempotent by ULID (same WP post, same URL) and images are already rendered, so there is no fal
 * spend — it's just a WordPress meta push per item.
 *
 * The re-push is THROTTLED: {@see PublishContent} jobs are dispatched in waves (chunk per interval,
 * staggered by delay) so the client's WordPress never sees a burst of hundreds of simultaneous pushes
 * and the queue drains steadily. The worker must be running for a queued re-push to complete (the
 * queue-health banner + drain button are the backstop).
 */
class RepushPublished
{
    public function __construct(private readonly SitemapSubmitter $sitemap) {}

    /**
     * @param  list<ContentKind>  $kinds
     * @return array{count: int, waves: int, minutes: float, sitemap_submitted: bool}
     */
    public function dispatch(Site $site, array $kinds, int $chunk = 10, int $intervalSeconds = 15, bool $dryRun = false): array
    {
        $chunk = max(1, $chunk);
        $intervalSeconds = max(0, $intervalSeconds);

        $ids = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereIn('kind', array_map(fn (ContentKind $k): string => $k->value, $kinds))
            ->where('status', ContentStatus::Published->value)
            ->whereNotNull('wp_post_id')
            ->orderBy('published_at')
            ->pluck('id')
            ->all();

        $count = count($ids);
        $waves = (int) ceil($count / $chunk);

        $sitemapSubmitted = false;
        if (! $dryRun) {
            foreach ($ids as $index => $id) {
                $wave = intdiv($index, $chunk);
                PublishContent::dispatch((string) $id)->delay(Carbon::now()->addSeconds($wave * $intervalSeconds));
            }
            $sitemapSubmitted = $this->maybeSubmitSitemap($site, $count, $waves, $intervalSeconds);
        }

        return [
            'count' => $count,
            'waves' => $waves,
            // Time until the LAST wave becomes available (waves - 1 gaps); the worker drains after.
            'minutes' => round(max(0, $waves - 1) * $intervalSeconds / 60, 1),
            'sitemap_submitted' => $sitemapSubmitted,
        ];
    }

    /**
     * Queue ONE Search Console sitemap submit per re-push run — delayed past the last wave so the plugin
     * sitemap's <lastmod> values are refreshed first. Gated on: config flag, GSC-connected, at least one
     * item pushed, and a per-site debounce window (sitemaps.submit is idempotent + Google recrawls from
     * <lastmod>, so more than one submit per window is wasted and Google discourages repeated resubmits).
     */
    private function maybeSubmitSitemap(Site $site, int $count, int $waves, int $intervalSeconds): bool
    {
        if ($count === 0 || ! (bool) config('services.google.sitemap_submit_on_repush', true)) {
            return false;
        }
        if (! $this->sitemap->connected($site)) {
            return false;
        }

        $hours = max(1, (int) config('services.google.sitemap_submit_debounce_hours', 12));
        if (! Cache::add('lp.sitemap_submit.'.$site->id, true, now()->addHours($hours))) {
            return false; // already submitted within the debounce window
        }

        $delaySeconds = max(0, $waves - 1) * $intervalSeconds + 60; // after the last wave becomes available
        SubmitSitemap::dispatch((string) $site->id)->delay(Carbon::now()->addSeconds($delaySeconds));

        return true;
    }
}
