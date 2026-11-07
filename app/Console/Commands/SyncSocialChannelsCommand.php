<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Social\ChannelSync;
use App\Support\SiteFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/** Pull the posting provider's connected profiles into each site's channels, matching GBP profiles to locations. */
class SyncSocialChannelsCommand extends Command
{
    protected $signature = 'launchpad:sync-social-channels {site? : A site (id, brand, or domain)} {--all : Every site}';

    protected $description = 'Sync social channels from the posting provider (GBP profiles matched to locations)';

    public function handle(ChannelSync $sync): int
    {
        $sites = $this->sites();
        if ($sites === null) {
            return self::FAILURE;
        }
        foreach ($sites as $site) {
            try {
                $r = $sync->sync($site);
            } catch (Throwable $e) {
                $this->error(sprintf('%s: %s', $site->brand_name, $e->getMessage()));

                continue;
            }
            $this->line(sprintf('<info>%s</info>: %d profile(s), %d GBP matched, %d disabled%s', $site->brand_name, $r['synced'], $r['matched'], $r['disabled'], $r['unmatched'] !== [] ? ' · unmatched GBP: '.implode(', ', $r['unmatched']) : ''));
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Site>|null */
    private function sites(): ?Collection
    {
        if ((bool) $this->option('all')) {
            return Site::withoutGlobalScopes()->orderBy('brand_name')->get();
        }
        $needle = trim((string) $this->argument('site'));
        if ($needle === '') {
            $this->warn('No site — pass a site, or --all.');

            return collect();
        }
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        return $matches->values();
    }
}
