<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\BeatabilityLane;
use App\Enums\MarketTier;
use App\Integrations\Serp\SerpProvider;
use App\KeywordGenerator\Beatability\BeatabilityEngine;
use App\KeywordGenerator\Bucketer;
use App\KeywordGenerator\Gap\GapAnalyzer;
use App\KeywordGenerator\Scoring\BusinessValueResolver;
use App\KeywordGenerator\Scoring\IntentClassifier;
use App\KeywordGenerator\Scoring\OpportunityScorer;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * End-to-end directed pipeline against the provider interfaces: discover →
 * bucket into silos (rule_sets) → score (opportunity × beatability) → gap
 * analysis → quick-wins-ordered gap-briefs. Scores are written back onto the
 * Keyword rows; unbucketed keywords are flagged as a gap signal.
 */
class KeywordPipeline
{
    public function __construct(
        private readonly SerpProvider $serp,
        private readonly Bucketer $bucketer,
        private readonly BeatabilityEngine $beatability,
        private readonly IntentClassifier $intentClassifier,
        private readonly BusinessValueResolver $businessValue,
        private readonly OpportunityScorer $scorer,
        private readonly GapAnalyzer $gaps,
    ) {}

    public function run(Site $site): PipelineResult
    {
        $silos = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->with('services')
            ->get();

        $priorityMarket = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('tier', MarketTier::Priority->value)
            ->first();

        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();

        $scored = [];
        foreach ($keywords as $keyword) {
            $silo = $this->resolveSilo($keyword, $silos);

            if ($silo === null) {
                $keyword->update(['status' => 'gap']);

                continue;
            }

            $intent = $this->intentClassifier->classify($keyword->query, $keyword->intent);
            $beatability = $this->beatability->assess($site, $keyword->query, $intent, $priorityMarket);

            $market = $beatability->lane === BeatabilityLane::LocalPack ? $priorityMarket : null;
            $businessValue = $this->businessValue->resolve($silo, $market);

            $metrics = $this->serp->metrics($keyword->query);
            $score = $this->scorer->score($metrics->volume, $metrics->difficulty, $intent, $businessValue, $beatability->score);

            $keyword->update([
                'silo_id' => $silo->id,
                'volume' => $metrics->volume,
                'difficulty' => $metrics->difficulty,
                'opportunity_score' => round($score->opportunity, 4),
                'beatability' => round($beatability->score, 4),
                'status' => $beatability->parked ? 'parked' : 'scored',
            ]);
            $keyword->setRelation('silo', $silo);

            $scored[] = new ScoredKeyword(
                keyword: $keyword,
                score: $score,
                beatability: $beatability,
                intent: $intent,
                serp: $this->serp->results($keyword->query),
                relatedTerms: $metrics->relatedTerms,
                market: $market,
            );
        }

        return new PipelineResult($scored, $this->gaps->analyze($site, $scored));
    }

    /**
     * @param  Collection<int, Silo>  $silos
     */
    private function resolveSilo(Keyword $keyword, $silos): ?Silo
    {
        if ($keyword->silo_id !== null) {
            return $silos->firstWhere('id', $keyword->silo_id)
                ?? Silo::withoutGlobalScope(SiteScope::class)->find($keyword->silo_id);
        }

        return $this->bucketer->bucket($keyword->query, $silos);
    }
}
