<?php

namespace App\Console\Commands;

use App\Interview\Prune\PruneEngine;
use App\Interview\Prune\PrunePlan;
use App\Interview\Prune\PruneRow;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Phase 4 — the prune VIEWER (read-only). Shows the volume-weighted candidate tree the
 * way the owner reviews it: grouped by silo, volume-sorted within (highest-upside
 * lean-ins first), with a per-silo summary (stated core vs lean-ins + their upside) and
 * the pending/confirmed state. Decisions are applied with `launchpad:prune-apply`.
 *
 *   launchpad:silo-prune {site} [--json]
 */
class SiloPruneCommand extends Command
{
    protected $signature = 'launchpad:silo-prune
        {site : the Site id}
        {--json : emit the plan as JSON}';

    protected $description = 'View the prune plan: candidates grouped by silo, volume-sorted, with per-silo summaries.';

    public function handle(PruneEngine $engine): int
    {
        $site = Site::query()->find($this->argument('site'));
        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $plan = $engine->plan($site);

        if ($this->option('json')) {
            $this->line((string) json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($plan);

        return self::SUCCESS;
    }

    private function render(PrunePlan $plan): void
    {
        $summaries = $plan->siloSummaries();
        foreach ($plan->bySilo() as $silo => $rows) {
            $s = $summaries[$silo];
            $this->newLine();
            $this->info("▌ {$silo}  ({$s['total']} spokes — {$s['core']} core, {$s['lean_ins']} lean-ins @ {$s['lean_in_volume']} searches)");
            foreach ($rows as $row) {
                $this->line($this->row($row));
            }
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
}
