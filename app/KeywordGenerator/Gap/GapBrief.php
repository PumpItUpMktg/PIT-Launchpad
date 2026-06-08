<?php

namespace App\KeywordGenerator\Gap;

use App\Enums\BeatabilityLane;

/**
 * The prescriptive directed-content contract for one gap. Generation executes
 * it into kit slots; it does not decide. Coverage requirements reuse the SERP
 * pull from beatability — no re-fetch.
 */
final class GapBrief
{
    /**
     * @param  list<string>  $altKeywords
     * @param  list<string>  $problemFraming
     * @param  list<string>  $coverageRequirements
     * @param  list<string>  $proofHooks
     * @param  array{pillar_content_id: string|null, sibling_silo_ids: list<string>}  $internalLinks
     * @param  array<string, mixed>  $seoTargets
     */
    public function __construct(
        public readonly string $targetKeyword,
        public readonly array $altKeywords,
        public readonly float $opportunity,
        public readonly float $beatability,
        public readonly BeatabilityLane $lane,
        public readonly string $intent,
        public readonly string $siloId,
        public readonly string $siloName,
        public readonly string $pageType,
        public readonly string $kit,
        public readonly array $problemFraming,
        public readonly array $coverageRequirements,
        public readonly array $proofHooks,
        public readonly array $internalLinks,
        public readonly string $differentiationAngle,
        public readonly string $ctaIntent,
        public readonly string $priorityLane,
        public readonly array $seoTargets,
        public readonly float $quickWin,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_keyword' => $this->targetKeyword,
            'alt_keywords' => $this->altKeywords,
            'opportunity' => $this->opportunity,
            'beatability' => $this->beatability,
            'lane' => $this->lane->value,
            'intent' => $this->intent,
            'silo_id' => $this->siloId,
            'silo_name' => $this->siloName,
            'page_type' => $this->pageType,
            'kit' => $this->kit,
            'problem_framing' => $this->problemFraming,
            'coverage_requirements' => $this->coverageRequirements,
            'proof_hooks' => $this->proofHooks,
            'internal_links' => $this->internalLinks,
            'differentiation_angle' => $this->differentiationAngle,
            'cta_intent' => $this->ctaIntent,
            'priority_lane' => $this->priorityLane,
            'seo_targets' => $this->seoTargets,
            'quick_win' => $this->quickWin,
        ];
    }
}
