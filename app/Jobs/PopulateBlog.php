<?php

namespace App\Jobs;

use App\Console\Commands\PopulateBlogCommand;
use App\ContentEngine\Feeds\BlogPopulator;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The HTTP-heavy tail of a blog-populate run for one site — the queued worker behind the operator's
 * "Populate blog now" action. The surface runs the cheap DB stages (rebucket + reconcile) inline for
 * an instant readiness read, then hands the feed fetch + candidate funnel here so the web request
 * never blocks on the keyword×geo feed fan-out. Idempotent: the funnel dedupes, so a repeat run never
 * doubles candidates. The CLI ({@see PopulateBlogCommand}) runs the whole chain
 * synchronously instead.
 */
class PopulateBlog implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $siteId) {}

    public function handle(BlogPopulator $populator): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $report = $populator->populate($site, ingest: true);
        Log::info('blog.populate.report', [
            'site_id' => $site->id,
            'candidates_created' => $report->candidatesCreated,
            'fetched' => $report->fetched,
            'feeds_active' => $report->feedsActive,
            'keywords_siloed' => $report->keywordsSiloed,
        ]);
    }
}
