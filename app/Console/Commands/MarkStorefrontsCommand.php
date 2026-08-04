<?php

namespace App\Console\Commands;

use App\Locations\StorefrontPromoter;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Marks a site's location-page Locations as STOREFRONT ({@see StorefrontPromoter}) so their own street
 * address renders on the page + in the LocalBusiness schema (both gate the address on `is_storefront`).
 * Only Locations that already carry an address are promoted; offices still missing an address are
 * reported so it can be populated (from GBP or by hand) first.
 *
 * PREVIEW BY DEFAULT — lists what it would promote and what's missing; `--apply` flips the flag. After an
 * apply, repush the affected location pages so the address ships.
 */
class MarkStorefrontsCommand extends Command
{
    protected $signature = 'launchpad:mark-storefronts {site? : Site id or brand name (omit to sweep every tenant)}
        {--apply : Actually flip is_storefront (default is a preview that changes nothing)}';

    protected $description = 'Promote location pages with a real address to storefront so the address shows on the page + schema. Preview by default; --apply to flip.';

    public function handle(StorefrontPromoter $promoter): int
    {
        $sites = $this->resolveSites();
        if ($sites === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $promoted = 0;
        $missing = 0;

        foreach ($sites as $site) {
            $plan = $promoter->plan($site, $apply);
            if ($plan['promote'] === [] && $plan['missing'] === []) {
                continue;
            }

            $this->newLine();
            $this->line("<info>{$site->brand_name}</info> ({$site->id}) — {$plan['already']} already storefront");

            foreach ($plan['promote'] as $row) {
                $verb = $apply ? '✓ promoted' : 'would promote';
                $this->line("  • {$verb}: {$row['name']} — {$row['address']}");
                $promoted++;
            }
            foreach ($plan['missing'] as $row) {
                $this->line("  • <comment>NO address in record</comment>: {$row['name']} — populate from GBP before it can show");
                $missing++;
            }
        }

        $this->newLine();
        if ($promoted === 0 && $missing === 0) {
            $this->info('Nothing to promote — every location page is already a storefront (or has no pinned Location).');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->comment("Preview only — nothing changed. Re-run with --apply to promote {$promoted} location(s).");
        } else {
            $this->info("Promoted {$promoted} location(s) to storefront. Repush the affected location pages so the address ships.");
        }
        if ($missing > 0) {
            $this->comment("{$missing} location(s) have NO address in the record — add the street address (matching GBP) then re-run.");
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Site>|null */
    private function resolveSites(): ?Collection
    {
        $arg = $this->argument('site');
        if ($arg === null) {
            return Site::query()->get();
        }

        $site = Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->first();
        if ($site === null) {
            $this->error("No site matches [{$arg}].");

            return null;
        }

        return collect([$site]);
    }
}
