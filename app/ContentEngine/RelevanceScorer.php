<?php

namespace App\ContentEngine;

use App\Enums\CandidateClassification;
use App\Enums\CandidateScope;
use App\Enums\RelevanceBand;
use App\Enums\ShelfLife;
use App\Integrations\Claude\ClaudeClient;
use App\Integrations\News\NewsItem;
use App\Models\Silo;
use App\SiloCreator\RuleSet;
use Illuminate\Support\Collection;

/**
 * The single cheap (Haiku) pass doing triple duty: relevance score + matched-
 * silo routing (against §4 rule_sets, passed in-prompt) + advisory-angle hint.
 * Dimensions: silo-match gate × advisory value × timeliness, a local-relevance
 * booster, and a brand-safety / sensitivity gate. The Claude call goes through
 * the thin ClaudeClient seam (Haiku-configured in production), so it is fully
 * mockable in tests.
 */
class RelevanceScorer
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly float $draftThreshold = 0.6,
        private readonly float $borderlineThreshold = 0.35,
    ) {}

    /**
     * @param  Collection<int, Silo>  $silos
     * @param  string|null  $hintSiloId  The originating feed's routing hint
     *                                   (Source.silo_id). A backstop only: when
     *                                   the model returns no match it falls back
     *                                   to the hint, so a hinted feed routes more
     *                                   precisely while an untagged feed still
     *                                   routes purely by content. The score still
     *                                   gates band (brand-safety + thresholds).
     */
    public function score(NewsItem $item, Collection $silos, ?string $hintSiloId = null): RelevanceResult
    {
        $data = $this->parse($this->claude->complete($this->prompt($item, $silos), $this->system()));

        $brandSafe = (bool) ($data['brand_safe'] ?? true);
        $score = (float) ($data['relevance'] ?? 0.0);
        $timeliness = (float) ($data['timeliness'] ?? 0.0);
        $localRelevance = (bool) ($data['local_relevance'] ?? false);
        // A competitor's OWN announcement / press release / promo is not a news hook for us — drop it.
        $competitorPromo = (bool) ($data['competitor_promo'] ?? false);
        $classification = CandidateClassification::tryFrom((string) ($data['classification'] ?? ''))
            ?? CandidateClassification::derive($timeliness, $localRelevance);

        $matchedSilo = isset($data['matched_silo'])
            ? $silos->first(fn (Silo $s) => strcasecmp($s->name, (string) $data['matched_silo']) === 0)
            : null;

        if ($matchedSilo === null && $hintSiloId !== null) {
            $matchedSilo = $silos->first(fn (Silo $s) => $s->id === $hintSiloId);
        }

        // Category guard: if the item hits the matched silo's EXCLUDE patterns it doesn't belong there (a
        // residential yard-drainage story mis-filed under "Commercial Pump Services"). Reject the routing so
        // it drops (no_silo_match) rather than mis-categorizing — never file a post in the wrong silo.
        if ($matchedSilo !== null && $this->hitsExcludes($item, $matchedSilo)) {
            $matchedSilo = null;
        }

        $band = match (true) {
            $competitorPromo => RelevanceBand::Dropped,               // competitor's own announcement — not our news
            ! $brandSafe => RelevanceBand::Dropped,
            $matchedSilo === null => RelevanceBand::Dropped,          // silo-match gate
            $score >= $this->draftThreshold => RelevanceBand::DraftReady,
            $score >= $this->borderlineThreshold => RelevanceBand::Borderline,
            default => RelevanceBand::Dropped,
        };

        return new RelevanceResult(
            score: $score,
            band: $band,
            matchedSiloId: $matchedSilo?->id,
            angleHint: isset($data['angle']) ? (string) $data['angle'] : null,
            advisoryValue: (float) ($data['advisory_value'] ?? 0.0),
            timeliness: $timeliness,
            localRelevance: $localRelevance,
            brandSafe: $brandSafe,
            rationale: (string) ($data['rationale'] ?? ''),
            classification: $classification,
            competitorPromo: $competitorPromo,
            // Two orthogonal axes from the dedicated numeric/boolean signals — decoupled so a story can be
            // (e.g.) local AND topical, which the single `classification` lane could not represent.
            shelfLife: ShelfLife::fromTimeliness($timeliness),
            scope: CandidateScope::fromLocal($localRelevance),
        );
    }

    /** True when the item's text matches any of the silo's exclude patterns — it doesn't belong there. */
    private function hitsExcludes(NewsItem $item, Silo $silo): bool
    {
        $text = mb_strtolower($item->text());
        foreach (RuleSet::fromArray($silo->rule_set ?? [])->excludePatterns as $pattern) {
            $pattern = mb_strtolower(trim($pattern));
            if ($pattern !== '' && str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Silo>  $silos
     */
    private function prompt(NewsItem $item, Collection $silos): string
    {
        $siloLines = $silos->map(function (Silo $silo) {
            $rules = RuleSet::fromArray($silo->rule_set ?? []);
            $line = "- {$silo->name}: ".implode(', ', $rules->includePatterns);
            if ($rules->excludePatterns !== []) {
                $line .= ' [NOT: '.implode(', ', $rules->excludePatterns).']';
            }

            return $line;
        })->implode("\n");

        return "Score this news item for a home-services brand's advisory blog.\n\n"
            ."Title: {$item->title}\nSummary: {$item->summary}\nSource: {$item->sourceName}\n\n"
            ."Silos (route to the best match by its include terms; NEVER route to a silo when the item matches its [NOT: …] terms; null if none fits):\n{$siloLines}\n\n"
            .'Return ONLY JSON: {"relevance":0..1,"matched_silo":"<silo name|null>","angle":"<advisory angle>",'
            .'"advisory_value":0..1,"timeliness":0..1,"local_relevance":true|false,"brand_safe":true|false,'
            .'"classification":"local|time_sensitive|evergreen","competitor_promo":true|false,"rationale":"..."}. '
            .'brand_safe is false for off-brand, controversial, or tragedy-exploitative items. '
            .'classification: "local" if the story is anchored to a specific place in/near the brand\'s service area; '
            .'else "time_sensitive" if it is a timely event/news hook whose value decays quickly; else "evergreen" for durable advisory topics. '
            .'competitor_promo is true ONLY when the item is a rival home-services company\'s OWN announcement, press release, '
            .'promotion, hiring notice, or ad (not our brand, and not neutral industry news) — such items must be dropped.';
    }

    private function system(): string
    {
        return 'You are a brand-safe content relevance scorer. Return strict JSON only. '
            .'Never recommend fear-mongering or tragedy-exploitative angles.';
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
