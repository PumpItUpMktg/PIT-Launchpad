<?php

namespace App\Console\Commands;

use App\Metrics\Milestones\MilestoneDeriver;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Derive the client narrative milestones (§ Client Dashboard v1, PR 5) from the metric spine + page-index
 * state, per site. Cheap and read-only over the DB (no external calls), so it runs inline rather than on a
 * queue.
 *
 *   sandhog:derive-milestones {site?}
 */
class DeriveMilestonesCommand extends Command
{
    protected $signature = 'sandhog:derive-milestones {site? : the Site id (default: every visible site)}';

    protected $description = 'Derive client milestones (first indexed / first page-1 keyword / blog-volume) from the metric spine.';

    public function handle(MilestoneDeriver $deriver): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No site found.');

            return self::FAILURE;
        }

        foreach ($sites as $site) {
            $keys = $deriver->derive($site);
            $this->line(sprintf('   %-28s → %d milestone(s): %s',
                $site->domain_url ?? $site->id, count($keys), $keys === [] ? '—' : implode(', ', $keys)));
        }

        $this->info(sprintf('Derived milestones for %d site(s).', $sites->count()));

        return self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function resolveSites(): Collection
    {
        $id = $this->argument('site');
        if ($id !== null) {
            $site = Site::query()->find($id);

            return $site === null ? collect() : collect([$site]);
        }

        return Site::query()->get();
    }
}
