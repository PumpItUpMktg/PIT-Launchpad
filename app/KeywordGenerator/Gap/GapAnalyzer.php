<?php

namespace App\KeywordGenerator\Gap;

use App\Enums\BeatabilityLane;
use App\Enums\ContentStatus;
use App\Enums\SiloType;
use App\KeywordGenerator\Pipeline\ScoredKeyword;
use App\Models\Content;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;

/**
 * Per silo: should-cover (scored opportunities) vs covered (existing Content)
 * → gaps. Each non-parked, non-covered, bucketed gap becomes a prescriptive
 * gap-brief, ordered into the quick-wins queue.
 */
class GapAnalyzer
{
    /**
     * @param  list<ScoredKeyword>  $scored
     */
    public function analyze(Site $site, array $scored): GapBriefQueue
    {
        $covered = array_flip(
            Content::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->whereNotNull('target_keyword_id')
                ->where('status', '!=', ContentStatus::Rejected->value)
                ->pluck('target_keyword_id')
                ->all()
        );

        $briefs = [];
        foreach ($scored as $item) {
            $keyword = $item->keyword;

            if ($item->beatability->parked || isset($covered[$keyword->id]) || $keyword->silo_id === null) {
                continue;
            }

            $silo = $keyword->relationLoaded('silo') && $keyword->silo !== null
                ? $keyword->silo
                : Silo::withoutGlobalScope(SiteScope::class)->find($keyword->silo_id);

            if ($silo === null) {
                continue;
            }

            $briefs[] = $this->buildBrief($site, $silo, $item);
        }

        return new GapBriefQueue($briefs);
    }

    private function buildBrief(Site $site, Silo $silo, ScoredKeyword $item): GapBrief
    {
        $silo->loadMissing('services.problems');
        $serviceIds = $silo->services->pluck('id')->all();

        $problemFraming = $silo->services
            ->flatMap(fn ($service) => $service->problems)
            ->pluck('phrase')
            ->take(8)
            ->values()
            ->all();

        $proofHooks = ProofItem::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('is_substantiated', true)
            ->whereHas('services', fn ($q) => $q->whereIn('services.id', $serviceIds))
            ->limit(6)
            ->get()
            ->map(fn (ProofItem $proof) => $proof->type->value.':'.$proof->id)
            ->all();

        $siblingIds = Silo::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('parent_silo_id', $silo->parent_silo_id)
            ->whereKeyNot($silo->id)
            ->pluck('id')
            ->map('strval')
            ->all();

        $coverage = array_values(array_unique([
            ...$item->relatedTerms,
            ...array_slice($item->serp->domains(), 0, 5),
        ]));

        $isService = $silo->type === SiloType::ServicePillar;
        $priorityLane = $item->beatability->longPlay ? 'long_play' : 'quick_win';
        $cta = $item->beatability->lane === BeatabilityLane::LocalPack ? 'call_now' : 'request_quote';

        return new GapBrief(
            targetKeyword: $item->keyword->query,
            altKeywords: array_slice($item->relatedTerms, 0, 5),
            opportunity: round($item->score->opportunity, 4),
            beatability: round($item->beatability->score, 4),
            lane: $item->beatability->lane,
            intent: $item->intent->value,
            siloId: $silo->id,
            siloName: $silo->name,
            pageType: 'cluster',
            kit: $isService ? 'service-page' : 'cluster',
            problemFraming: $problemFraming,
            coverageRequirements: $coverage,
            proofHooks: $proofHooks,
            internalLinks: [
                'pillar_content_id' => $silo->pillar_content_id,
                'sibling_silo_ids' => $siblingIds,
            ],
            differentiationAngle: 'Revenue/win-led: '.$silo->name.' — '.($problemFraming[0] ?? 'customer problem').' solved, proof-backed.',
            ctaIntent: $cta,
            priorityLane: $priorityLane,
            seoTargets: [
                'target_keyword' => $item->keyword->query,
                'title' => ucfirst($item->keyword->query),
            ],
            quickWin: round($item->score->quickWin, 4),
        );
    }
}
