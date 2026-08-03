<?php

namespace App\Console\Commands;

use App\Locations\CityKeywordTracker;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Assign city keywords ("{service} {city}") to a site's PRIORITY-city location pages so their rank is
 * tracked (§5 Phase 2). This only creates/assigns the keywords — the DataForSEO pull itself is the
 * existing path (the "Refresh rankings now" button, the daily pipeline, or launchpad:discover-keywords),
 * so no credits are spent here. Idempotent. Reports what it assigned; --dry-run previews without writing.
 */
class TrackCityKeywordsCommand extends Command
{
    protected $signature = 'launchpad:track-city-keywords {--site= : Site id or brand name} {--dry-run : Preview the keywords without writing}';

    protected $description = 'Assign "{service} {city}" tracking keywords to a site\'s priority-city location pages.';

    public function handle(CityKeywordTracker $tracker): int
    {
        $sites = $this->resolveSites();
        if ($sites->isEmpty()) {
            $this->error('No matching site.');

            return self::FAILURE;
        }

        foreach ($sites as $site) {
            if ($this->option('dry-run')) {
                $this->line("<info>{$site->brand_name}</info> — dry run (no keywords written). Run without --dry-run to assign.");

                continue;
            }

            $result = $tracker->assign($site);

            if ($result['cities'] === 0) {
                $this->line("<comment>{$site->brand_name}</comment>: no priority-city location pages — tier a market as 'priority' and publish its location page first.");

                continue;
            }

            $this->line(sprintf(
                '<info>%s</info>: %d city keyword(s) across %d priority city(ies) (%d new).',
                $site->brand_name,
                count($result['keywords']),
                $result['cities'],
                $result['created'],
            ));
            foreach ($result['keywords'] as $term) {
                $this->line("  • {$term}");
            }
        }

        $this->newLine();
        $this->info('Done. Pull rankings with "Refresh rankings now" (Operate → Service pages) or launchpad:discover-keywords.');

        return self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function resolveSites(): Collection
    {
        $arg = $this->option('site');
        if (is_string($arg) && $arg !== '') {
            return Site::query()->where('id', $arg)->orWhere('brand_name', $arg)->get();
        }

        return Site::query()->orderBy('brand_name')->get();
    }
}
