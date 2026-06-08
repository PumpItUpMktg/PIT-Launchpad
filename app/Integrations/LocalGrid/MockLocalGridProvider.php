<?php

namespace App\Integrations\LocalGrid;

/**
 * Programmable local-grid provider for tests and the default binding. Returns
 * canned grids keyed on "query|marketId"; otherwise a neutral default.
 */
class MockLocalGridProvider implements LocalGridProvider
{
    /** @var array<string, GridMetrics> */
    private array $grids = [];

    /**
     * @param  list<LocalPackCompetitor>  $packCompetitors
     */
    public function setGrid(string $query, string $marketId, float $avgRank, float $pctTop3, float $coverage, array $packCompetitors = []): static
    {
        $this->grids[$query.'|'.$marketId] = new GridMetrics($query, $avgRank, $pctTop3, $coverage, $packCompetitors);

        return $this;
    }

    public function grid(string $query, string $marketId): GridMetrics
    {
        return $this->grids[$query.'|'.$marketId] ?? new GridMetrics($query, avgRank: 10.0, pctTop3: 0.0, coverage: 0.5, packCompetitors: []);
    }
}
