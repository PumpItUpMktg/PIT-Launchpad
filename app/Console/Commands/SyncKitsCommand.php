<?php

namespace App\Console\Commands;

use App\Models\WireframeKit;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Console\Command;

/**
 * Sync the library wireframe kits into the database — the deploy-safe seed of reference data that
 * lives in the DB, not in code. Wireframe kits are seeded by {@see WireframeKitSeeder}; a deploy runs
 * `migrate` but NOT `db:seed`, so any kit added to the seeder (e.g. the standard-page kits) is MISSING
 * on prod until this runs — which is exactly why Core pages read "composer pending" with current code.
 *
 * Idempotent (the seeder upserts by name+version), so it is safe on every deploy and re-run. The
 * post-migrate hook in AppServiceProvider runs the same seed automatically outside the test env; this
 * command is the explicit, reportable path (and the one to add to a deploy step).
 */
class SyncKitsCommand extends Command
{
    protected $signature = 'launchpad:sync-kits';

    protected $description = 'Seed/refresh the library wireframe kits in the DB (idempotent). Run on deploy so new kits (e.g. the standard-page kits) actually exist on prod.';

    public function handle(): int
    {
        (new WireframeKitSeeder)->run();

        $kits = WireframeKit::query()->whereNull('site_id')->orderBy('page_type')->orderBy('name')->get();

        $this->info("Synced {$kits->count()} library wireframe kit(s):");
        foreach ($kits as $kit) {
            $schema = $kit->schema();
            $this->line("  • {$kit->name} (page_type={$schema->pageType->value}, v{$kit->version}, ".count($schema->slots).' slots)');
        }

        return self::SUCCESS;
    }
}
