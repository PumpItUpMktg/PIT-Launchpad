<?php

namespace App\Jobs;

use App\Integrations\IndexNow\IndexNowSubmitter;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget IndexNow ping for one freshly-published page — dispatched by §2's publish success so
 * Bing/Yandex/etc are told to crawl the URL immediately. Off the publish critical path (its own queued
 * job) and swallow-all: a stale plugin or unreachable IndexNow endpoint can never affect publishing.
 */
class PingIndexNow implements ShouldQueue
{
    use Queueable;

    /** No auto-retry — a missed ping is harmless (the daily/manual full-site submit covers it). */
    public int $tries = 1;

    public function __construct(public readonly string $contentId) {}

    public function handle(IndexNowSubmitter $submitter): void
    {
        $content = Content::withoutGlobalScope(SiteScope::class)->with('site')->find($this->contentId);
        $site = $content?->site;
        if ($content === null || $site === null) {
            return;
        }

        $home = rtrim((string) $site->domain_url, '/');
        if ($home === '') {
            return;
        }

        $url = $home.'/'.ltrim((string) $content->slug, '/');
        $result = $submitter->submitUrl($site, $url);

        if ($result['ok']) {
            // Stamp the successful submission so the live card can show a "Submitted to Bing" pill.
            $content->forceFill(['indexnow_submitted_at' => now()])->save();
        } else {
            Log::info('IndexNow ping skipped/failed', ['url' => $url, 'reason' => $result['reason']]);
        }
    }
}
