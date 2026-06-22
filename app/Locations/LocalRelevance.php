<?php

namespace App\Locations;

use App\Integrations\Local\LocalSignalProvider;
use App\Integrations\Local\LocalSignals;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Per-business location-page drip. The biggest towns build immediately; the rest sit in reserve
 * and graduate one at a time as each earns enough local relevance **for that specific business** —
 * the {@see LocalSignalProvider} resolves competitor density, review footprint, and local demand
 * per (site, town), so two sites covering the same town drip in a different order.
 *
 * Three operations:
 *  - {@see seedInitialSelection()} — first-run population seed: the auto-select tiers (major/large
 *    by default) build now; everything else is reserve. Runs only while the pool is untouched, so
 *    it never stomps an operator's curation.
 *  - {@see dripGraduate()} — promote reserve towns whose relevance score clears the threshold
 *    (the scheduled/triggered drip).
 *  - {@see forSite()} — the readiness read-model (every town with its score, tier, and state).
 *
 * Manual (owner-added) towns are priority pages and are left untouched by both writes.
 */
final class LocalRelevance
{
    public function __construct(private readonly LocalSignalProvider $signals) {}

    /**
     * First-run population seed. Selects the auto-select tiers into the build pool, but only while
     * no county-derived town is selected yet — so re-running (or running after an operator has
     * curated) is a no-op. Returns the number of towns newly selected.
     */
    public function seedInitialSelection(Site $site): int
    {
        $towns = $this->countyTowns($site);

        // Already curated (by a prior seed or the operator) — don't touch the pool.
        if ($towns->contains(fn (CoverageArea $t) => (bool) $t->page_selected)) {
            return 0;
        }

        $autoTiers = (array) config('launchpad.drip.auto_select_tiers', ['major', 'large']);

        $count = 0;
        foreach ($towns as $town) {
            if (in_array($town->size_tier, $autoTiers, true)) {
                $town->forceFill(['page_selected' => true])->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * The drip: promote every reserve town whose relevance score clears the threshold. Returns the
     * number of towns graduated this pass.
     */
    public function dripGraduate(Site $site): int
    {
        $threshold = (float) config('launchpad.drip.drip_threshold', 0.55);
        $trade = $this->trade($site);
        $cap = $this->populationCap($site);

        $count = 0;
        foreach ($this->countyTowns($site) as $town) {
            if ((bool) $town->page_selected) {
                continue; // already building
            }

            $signals = $this->signals->forTown($site->id, (string) $town->geo_id, $trade, $town->population);
            if ($this->score($signals, $cap) >= $threshold) {
                $town->forceFill(['page_selected' => true])->save();
                $count++;
            }
        }

        return $count;
    }

    /**
     * The readiness read-model for the UI: every covered town with its relevance score, tier,
     * selection state, and the raw per-business signals — biggest/most-ready first.
     *
     * @return list<array{
     *     geo_id: string, name: string, tier: string|null, population: int|null,
     *     selected: bool, manual: bool, score: float, ready: bool, signals: LocalSignals
     * }>
     */
    public function forSite(Site $site): array
    {
        $threshold = (float) config('launchpad.drip.drip_threshold', 0.55);
        $trade = $this->trade($site);
        $cap = $this->populationCap($site);

        $towns = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->orderByDesc('population')
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($towns as $town) {
            $signals = $this->signals->forTown($site->id, (string) $town->geo_id, $trade, $town->population);
            $score = $this->score($signals, $cap);

            $rows[] = [
                'geo_id' => (string) $town->geo_id,
                'name' => (string) $town->name,
                'tier' => $town->size_tier,
                'population' => $town->population,
                'selected' => (bool) $town->page_selected,
                'manual' => $town->source === 'manual',
                'score' => $score,
                'ready' => $score >= $threshold,
                'signals' => $signals,
            ];
        }

        return $rows;
    }

    /**
     * Blend the normalized signals into a 0–1 relevance score: population (the real Census anchor)
     * + local demand + the business's review footprint, less a competitor-saturation penalty.
     */
    public function score(LocalSignals $signals, int $populationCap): float
    {
        $weights = (array) config('launchpad.drip.weights', []);
        $wPop = (float) ($weights['population'] ?? 0.45);
        $wDemand = (float) ($weights['demand'] ?? 0.30);
        $wReviews = (float) ($weights['reviews'] ?? 0.25);
        $wPenalty = (float) ($weights['competition_penalty'] ?? 0.20);

        $popNorm = $populationCap > 0 && $signals->population !== null
            ? min(1.0, $signals->population / $populationCap)
            : 0.0;

        $score = ($wPop * $popNorm)
            + ($wDemand * $signals->demandIndex)
            + ($wReviews * $signals->marketReviewIndex)
            - ($wPenalty * $signals->competitorDensity);

        return max(0.0, min(1.0, $score));
    }

    /** @return Collection<int, CoverageArea> county-derived towns (manual rows are priority, untouched) */
    private function countyTowns(Site $site): Collection
    {
        return CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('source', '!=', 'manual')
            ->get();
    }

    /** The population that normalizes to 1.0 — the tenant's major-tier threshold. */
    private function populationCap(Site $site): int
    {
        return (int) $site->coverageThresholds()['major'];
    }

    private function trade(Site $site): string
    {
        $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->first();

        if ($blueprint === null) {
            return '';
        }

        return (string) ($blueprint->trade ?? '');
    }
}
