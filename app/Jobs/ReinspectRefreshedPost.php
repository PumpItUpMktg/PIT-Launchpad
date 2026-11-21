<?php

namespace App\Jobs;

use App\ContentEngine\Questions\RefreshCrawlCheck;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask Google again about one refreshed post's URL (URL Inspection, cache bypassed) on the worker, and write the
 * verdict + crawl time back. Queued by the row's "Check Google now" and by the daily checkpoints; unique per post.
 */
class ReinspectRefreshedPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $contentId) {}

    public function uniqueId(): string
    {
        return $this->contentId;
    }

    /** Stamp the request on the row (the panel shows "checking…" until the inspection lands) and dispatch. */
    public static function enqueue(Content $post): void
    {
        $meta = is_array($post->meta) ? $post->meta : [];
        $crawl = is_array($meta['refresh_crawl'] ?? null) ? $meta['refresh_crawl'] : [];
        $crawl['requested_at'] = Carbon::now()->toIso8601String();
        $meta['refresh_crawl'] = $crawl;
        $post->forceFill(['meta' => $meta])->save();

        self::dispatch($post->id);
    }

    public function handle(RefreshCrawlCheck $check): void
    {
        $post = Content::withoutGlobalScope(SiteScope::class)->find($this->contentId);
        if ($post === null) {
            return;
        }
        $site = Site::withoutGlobalScopes()->find($post->site_id);
        if ($site === null) {
            return;
        }
        $check->reinspect($site, $post);
    }
}
