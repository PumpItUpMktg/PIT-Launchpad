<?php

namespace App\Console\Commands;

use App\ContentEngine\Feeds\FeedIngestor;
use Illuminate\Console\Command;

class IngestFeedsCommand extends Command
{
    protected $signature = 'launchpad:ingest-feeds {--site= : Limit to a single site id} {--per-feed : Print the per-stage verdict for each feed}';

    protected $description = 'Fetch every active feed (generated + client) and route items through the §6a candidate funnel.';

    public function handle(FeedIngestor $ingestor): int
    {
        $siteId = $this->option('site');

        // A manual single-site run is unbounded (process everything now); the all-tenant scheduled run is
        // budget-bounded so it finishes inside the hour and the stalest untouched feeds lead the next tick.
        $budget = $siteId !== null ? null : (float) config('launchpad.feeds.ingest_budget_seconds', 2400);

        $result = $ingestor->ingestDue($budget, $siteId);

        $this->line(sprintf(
            '%d feeds processed, %d skipped (budget %s, elapsed %.1fs) · fetched %d → prefiltered-out %d → deduped %d → score-rejected %d → routed %d (parked %d, refresh %d) · %d unhealthy',
            $result['feeds'],
            $result['skipped'],
            $budget !== null ? $budget.'s' : 'none',
            $result['elapsed_ms'] / 1000,
            $result['fetched'],
            $result['prefiltered_out'],
            $result['deduped'],
            $result['score_rejected'],
            $result['routed'],
            $result['parked'],
            $result['refresh_marked'],
            $result['unhealthy'],
        ));

        if ($result['skipped'] > 0) {
            $this->warn("  {$result['skipped']} feed(s) not reached this run — they lead the next tick (stalest-first).");
        }

        if ($this->option('per-feed')) {
            foreach ($result['reports'] as $report) {
                $this->line("  • {$report->label}: {$report->line()}");
            }
        }

        return self::SUCCESS;
    }
}
