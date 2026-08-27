<?php

namespace App\Console\Commands;

use App\GeoGrid\CoverageGrid;
use App\Jobs\RunCoverageScan;
use App\Models\CoverageScanPlan;
use App\Models\Scopes\SiteScope;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dispatches the queued coverage scans for every plan whose schedule has come due, then advances each plan's
 * `next_run_at` by its cadence. Run daily from the scheduler; the plans themselves carry the real cadence
 * (monthly/weekly), so this just fires whatever is due today. Respects the hard per-plan request ceiling — a
 * plan that would exceed it is left DUE (not advanced) and reported, so the operator notices rather than it
 * silently never running.
 */
class CoverageRunDueCommand extends Command
{
    protected $signature = 'launchpad:run-due-coverage-plans {--dry-run : List what would run, dispatch nothing}';

    protected $description = 'Dispatch queued coverage scans for due plans and advance their next run.';

    public function handle(CoverageGrid $coverage): int
    {
        $now = Carbon::now();
        $ceiling = max(0, (int) config('launchpad.geo_grid.request_ceiling', 5000));

        $due = CoverageScanPlan::withoutGlobalScope(SiteScope::class)
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No coverage plans due.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $plansRun = 0;
        foreach ($due as $plan) {
            $keywordIds = $plan->keyword_ids ?: [];
            $location = $plan->location();

            if ($location === null || $keywordIds === []) {
                $plan->forceFill(['next_run_at' => $plan->cadence->advance($now)])->save();   // nothing to do; don't re-check daily

                continue;
            }

            $requests = $coverage->count($location) * count($keywordIds);
            if ($ceiling > 0 && $requests > $ceiling) {
                $this->warn("Skipped {$location->name}: {$requests} requests exceeds the ceiling ({$ceiling}). Narrow keywords or raise the ceiling — left due.");

                continue;   // leave DUE so it's visibly stuck, not silently skipped
            }

            if (! $this->option('dry-run')) {
                foreach ($keywordIds as $keywordId) {
                    RunCoverageScan::dispatch($plan->location_id, (string) $keywordId);
                    $dispatched++;
                }
                $plan->forceFill(['last_run_at' => $now, 'next_run_at' => $plan->cadence->advance($now)])->save();
            }
            $plansRun++;
            $this->line("  <info>✓</info> {$location->name} — ".count($keywordIds)." scan(s), {$requests} requests");
        }

        $this->newLine();
        $this->info($this->option('dry-run')
            ? "Dry run — {$plansRun} plan(s) would run."
            : "Dispatched {$dispatched} scan(s) across {$plansRun} plan(s).");

        return self::SUCCESS;
    }
}
