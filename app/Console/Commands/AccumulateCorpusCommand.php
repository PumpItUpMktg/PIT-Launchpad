<?php

namespace App\Console\Commands;

use App\KeywordGenerator\Corpus\CorpusAccumulator;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Build (or refresh) a tenant's keyword corpus — Part 1 of keyword-first structure generation. Prints
 * the corpus size so breadth is a visible checkpoint: a narrow corpus for a real trade is the signal to
 * widen expansion beyond related_keywords. Re-runnable and non-destructive to operator dispositions.
 */
class AccumulateCorpusCommand extends Command
{
    protected $signature = 'launchpad:accumulate-corpus {--site= : Site id (required)}';

    protected $description = 'Accumulate the tenant keyword corpus from seeds via DataForSEO (keyword-first Part 1).';

    public function handle(CorpusAccumulator $accumulator): int
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

        $result = $accumulator->accumulate($site);

        $this->info("Corpus for {$site->brand_name}: {$result->total} terms "
            ."({$result->added} added, {$result->refreshed} refreshed) from {$result->seedCount} seeds"
            .($result->geoFiltered > 0 ? "; {$result->geoFiltered} geo-modified terms dropped" : '')
            .($result->capped ? ' — capped to the breadth guardrail' : ''));

        if ($result->total < 100) {
            $this->warn('Corpus is narrow (<100 terms) — related_keywords may be under-expanding this trade; '
                .'consider adding keyword_ideas/suggestions expansion.');
        }

        return self::SUCCESS;
    }
}
