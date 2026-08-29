<?php

namespace App\Console\Commands;

use App\Citations\DirectoryRankRefresher;
use App\Citations\DirectoryRating;
use App\Models\Directory;
use Illuminate\Console\Command;

/**
 * Refresh directory authority ranks and recompute their SEO values (§ Citations, PR5). Idempotent and safe to
 * re-run; `--no-refresh` recomputes value from the ranks already stored (useful when domain authority is
 * still on the mock binding). Prints the rated count so the operator sees the catalog was scored.
 */
class RateDirectoriesCommand extends Command
{
    protected $signature = 'launchpad:rate-directories {--no-refresh : Skip the domain-rank refresh and only recompute SEO value}';

    protected $description = 'Refresh directory domain ranks and recompute their computed SEO value.';

    public function handle(DirectoryRankRefresher $refresher, DirectoryRating $rating): int
    {
        $refreshed = 0;
        if (! $this->option('no-refresh')) {
            $refreshed = $refresher->refreshAll();
        }

        $rated = 0;
        foreach (Directory::query()->where('is_active', true)->get() as $directory) {
            $rating->rate($directory);
            $rated++;
        }

        $this->info("Rated {$rated} active directories".($refreshed > 0 ? "; refreshed {$refreshed} domain ranks." : '.'));

        return self::SUCCESS;
    }
}
