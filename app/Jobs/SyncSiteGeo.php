<?php

namespace App\Jobs;

use App\Geo\GeoVisibilityAudit;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Run one site's GEO visibility audit off the web request (the "Run GEO check" operator action dispatches
 * this so the console returns immediately while the web-search answers + Haiku judging happen on the worker).
 * Budget-bounded inside the audit; safe to re-dispatch.
 */
class SyncSiteGeo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly string $siteId) {}

    public function handle(GeoVisibilityAudit $audit): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site !== null) {
            $audit->audit($site);
        }
    }
}
