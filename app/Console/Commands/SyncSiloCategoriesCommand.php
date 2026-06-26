<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiloCategories;
use App\Models\Site;
use App\Publishing\PublishSiloService;
use Illuminate\Console\Command;

/**
 * Project a site's silo tree into WP categories on demand — the §4 silos → /silo push, roots-first
 * and idempotent by ULID. The Finalize-time {@see SyncSiloCategories} does this for new
 * tenants; this command is the backfill for a tenant finalized BEFORE that trigger existed (or whose
 * earlier push 404'd against a stale companion plugin) — once its plugin is current and a WP
 * connection is wired, run this to create the categories without re-finalizing.
 *
 * Synchronous (no FPM clock on the console). Re-runnable safely.
 */
class SyncSiloCategoriesCommand extends Command
{
    protected $signature = 'launchpad:sync-silo-categories {site : Site id or brand name}';

    protected $description = 'Push a site\'s silo tree to WordPress categories (roots-first, idempotent by ULID). The on-demand backfill for the Finalize-time projection.';

    public function handle(PublishSiloService $service): int
    {
        $site = Site::query()->find($this->argument('site'))
            ?? Site::query()->where('brand_name', $this->argument('site'))->first();

        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $count = $service->publishSite($site);

        $this->info($count === 0
            ? "No silos to push for {$site->brand_name}."
            : "Pushed {$count} silo categor(ies) for {$site->brand_name}.");

        return self::SUCCESS;
    }
}
