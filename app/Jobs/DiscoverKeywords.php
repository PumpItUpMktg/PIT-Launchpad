<?php

namespace App\Jobs;

use App\Console\Commands\DiscoverKeywordsCommand;
use App\Enums\PipelineTrigger;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * On-demand §5 keyword discovery for one site — the queued worker behind the operator's "Discover
 * keywords" action. Runs {@see SitePipelineRefresher} with `force: true` to bypass the discovery
 * cadence (the operator asked for it now), filling the site's silos with keyword targets via the
 * bound DataForSEO providers. Off the web request so a slow discovery never times out an FPM worker;
 * the CLI ({@see DiscoverKeywordsCommand}) runs the same thing synchronously.
 */
class DiscoverKeywords implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $siteId) {}

    public function handle(SitePipelineRefresher $refresher): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $refresher->refresh($site, PipelineTrigger::Manual, force: true);
    }
}
