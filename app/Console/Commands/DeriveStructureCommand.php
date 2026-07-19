<?php

namespace App\Console\Commands;

use App\KeywordGenerator\Derive\DerivationPipeline;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Derive the silo structure from a tenant's demand clusters — Part 3 of keyword-first structure
 * generation. Zero thin silos at output (thin clusters merge first); prints the silo count, the
 * service-mapping split, and the demand-without-service finding count.
 */
class DeriveStructureCommand extends Command
{
    protected $signature = 'launchpad:derive-structure {--site= : Site id (required)}';

    protected $description = 'Derive the silo tree from demand clusters (keyword-first Part 3).';

    public function handle(DerivationPipeline $pipeline): int
    {
        $siteId = $this->option('site');
        if (! is_string($siteId) || $siteId === '') {
            $this->error('Pass --site=<id>.');

            return self::FAILURE;
        }

        $site = Site::query()->find($siteId);
        if ($site === null) {
            $this->error("No site {$siteId}.");

            return self::FAILURE;
        }

        $result = $pipeline->derive($site);

        $this->info("Derived {$site->brand_name}: {$result->silos} silos (zero thin).");
        $this->info("Services mapped: {$result->servicesMapped} ({$result->servicesFlagged} flagged for review).");
        $this->info("Demand without service: {$result->demandFindings} finding(s).");

        return self::SUCCESS;
    }
}
