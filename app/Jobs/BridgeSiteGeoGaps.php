<?php

namespace App\Jobs;

use App\Geo\GeoGapBridge;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bridge one site's absent GEO gaps into directed content candidates off the web request (the "Bridge
 * gaps to content" operator action dispatches this). No-op when there are no absent gaps. Idempotent —
 * safe to re-dispatch; already-bridged prompts are reused, not duplicated.
 */
class BridgeSiteGeoGaps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $siteId) {}

    public function handle(GeoGapBridge $bridge): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site !== null) {
            $bridge->bridge($site);
        }
    }
}
