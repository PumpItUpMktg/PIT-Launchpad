<?php

namespace App\Console\Commands;

use App\ContentEngine\Feeds\FeedIngestor;
use App\Models\Site;
use Illuminate\Console\Command;

class IngestFeedsCommand extends Command
{
    protected $signature = 'launchpad:ingest-feeds {--site= : Limit to a single site id}';

    protected $description = 'Fetch every active feed (generated + client) and route items through the §6a candidate funnel.';

    public function handle(FeedIngestor $ingestor): int
    {
        foreach ($this->sites() as $site) {
            $result = $ingestor->ingestSite($site);
            $this->line(sprintf(
                '%s: %d feeds → %d candidates, %d parked, %d unhealthy',
                $site->id,
                $result['feeds'],
                $result['candidates'],
                $result['parked'],
                $result['unhealthy'],
            ));
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
