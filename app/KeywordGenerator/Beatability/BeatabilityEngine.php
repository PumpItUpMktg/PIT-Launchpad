<?php

namespace App\KeywordGenerator\Beatability;

use App\Enums\BeatabilityLane;
use App\Enums\CompetitorClass;
use App\Enums\IntentLevel;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\Serp\SerpProvider;
use App\Models\Market;
use App\Models\Site;

/**
 * Lane-aware beatability: the realistic chance THIS site ranks, assessed in the
 * winnable lane. Organic is gated by site authority + competitor strength;
 * local-pack is decided by the local competitor set (not domain authority) and
 * is scored per (keyword × market). Below the floor a keyword is parked unless
 * flagged a strategic long-play.
 */
class BeatabilityEngine
{
    public function __construct(
        private readonly SerpProvider $serp,
        private readonly LocalGridProvider $grid,
        private readonly LaneClassifier $lanes,
        private readonly CompetitorClassifier $classifier,
        private readonly SiteAuthority $authority,
        private readonly float $floor = 0.2,
    ) {}

    public function assess(Site $site, string $query, IntentLevel $intent, ?Market $market = null, bool $longPlay = false): BeatabilityResult
    {
        $lane = $this->lanes->classify($query, $intent);

        [$score, $rationale, $marketId] = $lane === BeatabilityLane::LocalPack
            ? $this->localPack($query, $market)
            : $this->organic($site, $query);

        $parked = $score < $this->floor && ! $longPlay;

        return new BeatabilityResult($score, $lane, $rationale, $parked, $longPlay, $marketId);
    }

    /**
     * @return array{0: float, 1: string, 2: string|null}
     */
    private function localPack(string $query, ?Market $market): array
    {
        if ($market === null) {
            return [0.5, 'local_pack: no market supplied; neutral estimate.', null];
        }

        $grid = $this->grid->grid($query, $market->id);
        $total = count($grid->packCompetitors);

        if ($total === 0) {
            return [0.7, 'local_pack: open pack, no entrenched competitors.', $market->id];
        }

        $local = 0;
        $nationalAgg = 0;
        foreach ($grid->packCompetitors as $competitor) {
            $class = $this->classifier->classify($competitor->domain ?? $competitor->name);
            if ($class === CompetitorClass::LocalCompetitor) {
                $local++;
            } elseif (in_array($class, [CompetitorClass::NationalBigBox, CompetitorClass::AggregatorDirectory], true)) {
                $nationalAgg++;
            }
        }

        $localShare = $local / $total;
        $nationalAggShare = $nationalAgg / $total;
        $score = $this->clamp(0.85 * $localShare + 0.15 - 0.5 * $nationalAggShare);

        $rationale = sprintf('local_pack: %d local vs %d national/aggregator of %d.', $local, $nationalAgg, $total);

        return [$score, $rationale, $market->id];
    }

    /**
     * @return array{0: float, 1: string, 2: string|null}
     */
    private function organic(Site $site, string $query): array
    {
        $results = $this->serp->results($query);
        $total = count($results->results);

        $strong = 0;
        foreach ($results->results as $result) {
            $class = $this->classifier->classify($result->domain);
            if (in_array($class, [CompetitorClass::EditorialGov, CompetitorClass::NationalBigBox, CompetitorClass::AggregatorDirectory], true)) {
                $strong++;
            }
        }

        $strongShare = $total > 0 ? $strong / $total : 0.0;
        $tier = $this->authority->tierFor($site);
        $score = $this->clamp($tier->organicStrength() * (1 - 0.7 * $strongShare));

        $rationale = sprintf('organic: %s authority vs %d/%d high-authority results.', $tier->value, $strong, $total);

        return [$score, $rationale, null];
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
