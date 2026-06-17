<?php

namespace App\Console\Commands;

use App\Interview\Prune\PrunePlan;
use App\Interview\Prune\Pruner;
use App\Interview\Prune\PruneRow;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Phase 4 — the prune (headless surface). Shows the volume-weighted candidate tree and
 * applies owner decisions, routing each spoke per the table (offer / future / capture /
 * skip) and confirming the blueprint once every non-fringe candidate is decided. The
 * conversational owner-facing UI layers on top of this engine later.
 *
 *   launchpad:silo-prune {site}                 # show the plan (pending vs decided)
 *   launchpad:silo-prune {site} --accept-core   # bulk-confirm the core offerings
 *   launchpad:silo-prune {site} --apply=path    # apply a decisions JSON ({name|id: outcome})
 *   launchpad:silo-prune {site} --confirm       # lock the blueprint if the gate passes
 */
class SiloPruneCommand extends Command
{
    protected $signature = 'launchpad:silo-prune
        {site : the Site id}
        {--accept-core : route every undecided core spoke (offer, or capture if it is a content page)}
        {--apply= : path to a decisions JSON file mapping spoke name|id → offer|future|capture|skip}
        {--confirm : confirm the blueprint if every non-fringe candidate is decided}
        {--json : emit the plan as JSON}';

    protected $description = 'Prune the candidate tree: route candidates (offer/future/capture/skip) and confirm the blueprint.';

    public function handle(Pruner $pruner): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        if ($this->option('accept-core')) {
            $this->info('Accepted '.$pruner->acceptCore($site).' core spokes.');
        }

        if (($path = $this->option('apply')) !== null) {
            if (($decisions = $this->readDecisions((string) $path)) === null) {
                return self::FAILURE;
            }
            $result = $pruner->apply($site, $decisions);
            $this->info("Applied {$result['applied']} decision(s).");
            if ($result['unmatched'] !== []) {
                $this->warn('Unmatched: '.implode(', ', $result['unmatched']));
            }
        }

        if ($this->option('confirm')) {
            $result = $pruner->confirm($site);
            $result['confirmed']
                ? $this->info('Blueprint CONFIRMED — directed-coverage layer locked.')
                : $this->error("Cannot confirm — {$result['pending']} candidate(s) still undecided.");
        }

        $plan = $pruner->plan($site);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($plan);

        return self::SUCCESS;
    }

    private function render(PrunePlan $plan): void
    {
        $currentSilo = null;
        foreach ($plan->rows as $row) {
            if ($row->isFringe()) {
                continue;
            }
            if ($row->silo !== $currentSilo) {
                $currentSilo = $row->silo;
                $this->newLine();
                $this->info("▌ {$currentSilo}");
            }
            $this->line($this->row($row));
        }

        if ($plan->fringe() !== []) {
            $this->newLine();
            $this->warn('▌ Fringe handoff (→ Routing layer; not decided here)');
            foreach ($plan->fringe() as $row) {
                $this->line('   '.$row->name);
            }
        }

        $this->newLine();
        $this->line(sprintf('%d decidable · %d pending · %d fringe · %s',
            count($plan->decidable()), count($plan->pending()), count($plan->fringe()),
            $plan->confirmed ? 'CONFIRMED' : ($plan->isComplete() ? 'ready to confirm' : 'incomplete')));
    }

    private function row(PruneRow $row): string
    {
        $mark = $row->isDecided() ? $row->status->value : 'PENDING';
        $vol = $row->volume === null ? '   —' : sprintf('%4d', $row->volume);
        $note = $row->connectionNote !== null ? '  — '.$row->connectionNote : '';

        return sprintf('   %-8s %s  %-10s %s%s', $mark, $vol, $row->tag->value, $row->name, $note);
    }

    /**
     * @return array<string, string>|null
     */
    private function readDecisions(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error("Decisions file not found: {$path}");

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->error('Decisions file is not a JSON object.');

            return null;
        }

        return array_map('strval', $decoded);
    }
}
