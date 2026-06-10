<?php

namespace App\Console\Commands;

use App\ContentEngine\Feeds\FeedIngestor;
use App\Models\Site;
use Illuminate\Console\Command;

class IngestFeedsCommand extends Command
{
    protected $signature = 'launchpad:ingest-feeds {--site= : Limit to a single site id} {--per-feed : Print the per-stage verdict for each feed}';

    protected $description = 'Fetch every active feed (generated + client) and route items through the §6a candidate funnel.';

    public function handle(FeedIngestor $ingestor): int
    {
        foreach ($this->sites() as $site) {
            $result = $ingestor->ingestSite($site);

            $this->line(sprintf(
                '%s (%s): %d feeds · fetched %d → prefiltered-out %d → deduped %d → score-rejected %d → routed %d (parked %d, refresh %d) · %d unhealthy',
                $site->brand_name,
                $site->id,
                $result['feeds'],
                $result['fetched'],
                $result['prefiltered_out'],
                $result['deduped'],
                $result['score_rejected'],
                $result['routed'],
                $result['parked'],
                $result['refresh_marked'],
                $result['unhealthy'],
            ));

            if ($this->option('per-feed')) {
                foreach ($result['reports'] as $report) {
                    $this->line("  • {$report->label}: {$report->line()}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return iterable<int, Site>
     */
    private function sites(): iterable
    {
        $site = $this->option('site');

        return $site !== null
            ? Site::query()->whereKey($site)->get()
            : Site::query()->get();
    }
}
