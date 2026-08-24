<?php

namespace App\Jobs;

use App\Geo\GeoPromptSeeder;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-seed one site's GEO prompt matrix off the web request (the "Auto-seed prompts" operator action
 * dispatches this). Idempotent, so re-dispatch is safe.
 */
class SeedSiteGeoPrompts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $siteId) {}

    public function handle(GeoPromptSeeder $seeder): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);
        if ($site !== null) {
            $seeder->seed($site);
        }
    }
}
