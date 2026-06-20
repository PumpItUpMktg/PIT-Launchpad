<?php

namespace App\Console\Commands;

use App\Locations\LocalRelevance;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The location-page drip. On first run it seeds the population-based selection (the major/large
 * towns build immediately); on every run it graduates reserve towns that have earned enough local
 * relevance for that business. Schedule it to drip towns live over time, or run it for one site.
 *
 *   launchpad:drip-towns [--site=ID]
 */
class DripTownsCommand extends Command
{
    protected $signature = 'launchpad:drip-towns
        {--site= : a single Site id (default: every site)}';

    protected $description = 'Seed population-based town selection and graduate reserve towns that earned local relevance.';

    public function handle(LocalRelevance $relevance): int
    {
        $sites = $this->sites();
        if ($sites->isEmpty()) {
            $this->error('No site(s) to process.');

            return self::FAILURE;
        }

        $seeded = 0;
        $graduated = 0;
        foreach ($sites as $site) {
            $s = $relevance->seedInitialSelection($site);
            $g = $relevance->dripGraduate($site);
            $seeded += $s;
            $graduated += $g;

            if ($s > 0 || $g > 0) {
                $this->line(sprintf('  %-30s seeded %d · graduated %d', $site->name ?? $site->id, $s, $g));
            }
        }

        $this->info("Drip complete: {$seeded} seeded, {$graduated} graduated across {$sites->count()} site(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function sites(): Collection
    {
        $id = $this->option('site');
        if ($id !== null) {
            $site = Site::query()->find($id);

            return $site === null ? collect() : collect([$site]);
        }

        return Site::query()->get();
    }
}
