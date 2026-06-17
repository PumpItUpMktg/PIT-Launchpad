<?php

namespace App\Console\Commands;

use App\Interview\Prune\PruneEngine;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Phase 4 — apply a prune decision-set (headless mutation path; the Filament UI writes
 * through the same engine). Applies per-spoke decisions (confirm / route / re-tag /
 * granularity) and silo-level decisions (fold / rename / confirm), then — with
 * --confirm — locks the blueprint through the hard gate.
 *
 *   launchpad:prune-apply {site} {decisions.json} [--accept-core] [--confirm] [--json]
 *
 * decisions.json:
 *   {
 *     "silos": { "Sewage Pumps": {"fold_into": "Sump Pumps"},
 *                "Sump Pumps": {"rename": "Pumps"},
 *                "Commercial & Industrial Pump Services": {"confirm": true} },
 *     "spokes": { "Foundation Crack Repair & Sealing": {"outcome": "offer", "tag": "core"},
 *                 "Battery Backup Sump Pump": {"outcome": "offer", "granularity": "own_page"},
 *                 "Curtain Drain Installation": {"outcome": "skip"} }
 *   }
 */
class SiloPruneApplyCommand extends Command
{
    protected $signature = 'launchpad:prune-apply
        {site : the Site id}
        {decisions : path to the decision-set JSON}
        {--accept-core : also bulk-confirm every undecided core spoke}
        {--confirm : confirm the blueprint if every non-fringe candidate is decided}
        {--json : emit the resulting plan as JSON}';

    protected $description = 'Apply a prune decision-set (spoke routing/re-tag/granularity + silo fold/rename/confirm) to a site.';

    public function handle(PruneEngine $engine): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('decisions');
        if (! is_file($path)) {
            $this->error("Decisions file not found: {$path}");

            return self::FAILURE;
        }
        $set = json_decode((string) file_get_contents($path), true);
        if (! is_array($set)) {
            $this->error('Decisions file is not a JSON object.');

            return self::FAILURE;
        }

        $result = $engine->applyDecisionSet($site, $set);
        $this->info(sprintf('Applied %d spoke decision(s) · %d renamed · %d folded · %d silo-confirmed.',
            $result['spokes_applied'], $result['silos_renamed'], $result['silos_folded'], $result['silos_confirmed']));
        if ($result['spokes_unmatched'] !== []) {
            $this->warn('Unmatched: '.implode(', ', $result['spokes_unmatched']));
        }

        if ($this->option('accept-core')) {
            $this->info('Accepted '.$engine->acceptCore($site).' core spokes.');
        }

        if ($this->option('confirm')) {
            $gate = $engine->confirm($site);
            $gate['confirmed']
                ? $this->info('Blueprint CONFIRMED — directed-coverage layer locked.')
                : $this->error("Cannot confirm — {$gate['pending']} candidate(s) still undecided.");
        }

        $plan = $engine->plan($site);
        if ($this->option('json')) {
            $this->line((string) json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(sprintf('%d decidable · %d pending · %s',
            count($plan->decidable()), count($plan->pending()),
            $plan->confirmed ? 'CONFIRMED' : ($plan->isComplete() ? 'ready to confirm' : 'incomplete')));

        return self::SUCCESS;
    }
}
