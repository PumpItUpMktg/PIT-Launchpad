<?php

namespace App\Console\Commands;

use App\KeywordGenerator\Cluster\ClusteringPipeline;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Cluster a tenant's keyword corpus into demand clusters — Part 2 of keyword-first structure generation.
 * Prints the cluster count and the SERP call count (the only SERP spend), so cost is visible per run.
 */
class ClusterCorpusCommand extends Command
{
    protected $signature = 'launchpad:cluster-corpus {--site= : Site id (required)}';

    protected $description = 'Cluster the tenant keyword corpus by demand (keyword-first Part 2).';

    public function handle(ClusteringPipeline $pipeline): int
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

        $result = $pipeline->cluster($site);

        $this->info("Clustered {$site->brand_name}: {$result->clusters} clusters, {$result->dropped} dropped off-trade.");
        $this->info("SERP calls: {$result->serpCalls} (head candidates only).");

        return self::SUCCESS;
    }
}
