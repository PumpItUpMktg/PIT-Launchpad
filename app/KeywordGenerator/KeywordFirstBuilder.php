<?php

namespace App\KeywordGenerator;

use App\Interview\Arrange\AutoArrangeRunner;
use App\KeywordGenerator\Cluster\ClusteringPipeline;
use App\KeywordGenerator\Corpus\CorpusAccumulator;
use App\KeywordGenerator\Derive\DerivationPipeline;
use App\KeywordGenerator\Derive\DerivationResult;
use App\Models\KeywordCorpus;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Part 4 — the keyword-first "Re-ground & re-arrange": rebuilds a site's structure on the accumulate →
 * cluster → derive pipeline instead of the catalog-first expander, then runs the existing auto-arrange
 * so Prune reads the same shape as ever. Accumulation is skipped when the corpus is fresh (it's the paid
 * DataForSEO step); clustering + derivation always re-run so structure tracks the latest demand.
 * Regeneration is destructive to arrangement (v1): re-deriving replaces the candidate spoke tree.
 */
final class KeywordFirstBuilder
{
    public function __construct(
        private readonly CorpusAccumulator $accumulator,
        private readonly ClusteringPipeline $clusterer,
        private readonly DerivationPipeline $deriver,
        private readonly AutoArrangeRunner $arranger,
    ) {}

    public function build(Site $site): DerivationResult
    {
        if ($this->corpusStale($site)) {
            $this->accumulator->accumulate($site);
        }

        $this->clusterer->cluster($site);
        $result = $this->deriver->derive($site);

        // Re-arrange the derived tree (dedup / sub-hub / keyword collisions) — Prune reads the result.
        $this->arranger->run($site);

        return $result;
    }

    /** The corpus is stale (or absent) → re-accumulate before clustering; otherwise reuse it. */
    private function corpusStale(Site $site): bool
    {
        $days = (int) config('launchpad.keyword_first.corpus_stale_days', 30);
        $latest = KeywordCorpus::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->max('last_refreshed_at');

        return $latest === null || Carbon::parse($latest)->lt(now()->subDays($days));
    }
}
