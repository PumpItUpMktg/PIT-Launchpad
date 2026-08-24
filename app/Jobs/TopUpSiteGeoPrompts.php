<?php

namespace App\Jobs;

use App\Geo\GeoPromptTopUp;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Generate assisted weakness top-ups for one site off the web request (the "Generate top-ups" operator
 * action dispatches this — it makes a Claude call per absent gap). No-op when there are no absent gaps.
 */
class TopUpSiteGeoPrompts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly string $siteId) {}

    public function handle(GeoPromptTopUp $topUp): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site !== null) {
            $topUp->topUp($site);
        }
    }
}
