<?php

namespace App\Jobs;

use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget Search Console sitemap submit — dispatched ONCE after a bulk re-push (delayed past the
 * last wave so the plugin sitemap's <lastmod> values are already refreshed) so Google re-crawls the
 * changed set. Off the request path and swallow-all: a GSC hiccup or a not-yet-connected tenant can never
 * affect the re-push itself. Idempotent — sitemaps.submit just refreshes the existing submission.
 */
class SubmitSitemap implements ShouldQueue
{
    use Queueable;

    /** No auto-retry — a missed submit is harmless (Google recrawls from <lastmod>; the button re-submits). */
    public int $tries = 1;

    public function __construct(public readonly string $siteId) {}

    public function handle(SitemapSubmitter $submitter): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null || ! $submitter->connected($site)) {
            return;
        }

        $result = $submitter->submit($site);
        if (! $result['ok']) {
            Log::info('Sitemap submit after re-push skipped/failed', ['site' => $this->siteId, 'reason' => $result['reason']]);
        }
    }
}
