<?php

namespace App\Locations\Dma;

use App\Enums\MunicipalityType;
use App\Models\CoverageArea;

/**
 * Resolves the distinct set of covered metros from a site's coverage municipalities:
 * each county subdivision's GEOID encodes its county (state+county = first 5 digits) →
 * Nielsen DMA → DataForSEO location_name; deduped. A state-level location is the fallback
 * for areas that don't map to a DMA (a place GEOID, or an unmapped county) — BUT only for
 * states with NO DMA coverage at all. When DMAs already cover a state (e.g. the NY +
 * Philadelphia DMAs together span all of NJ), emitting the whole state on top would
 * double-count it, so the state fallback is suppressed there. The radius already crossed
 * state lines in the Locations layer, so multiple states/DMAs are expected.
 */
final class MetroResolver
{
    public function __construct(private readonly DmaTable $table) {}

    /**
     * @param  iterable<CoverageArea>  $coverage
     * @return list<Metro>
     */
    public function forCoverage(iterable $coverage): array
    {
        /** @var array<string, Metro> $metros keyed by location_name */
        $metros = [];
        /** @var array<string, true> $dmaStates USPS states already covered by a DMA */
        $dmaStates = [];
        /** @var array<string, string> $fallbackCandidates USPS state => state location_name */
        $fallbackCandidates = [];

        foreach ($coverage as $area) {
            $dma = $this->dmaFor($area);
            if ($dma !== null) {
                $metros[$dma] ??= new Metro($this->display($dma), $dma);
                if ($area->state !== null) {
                    $dmaStates[strtoupper($area->state)] = true;
                }

                continue;
            }

            if ($area->state !== null) {
                $location = $this->table->locationForState($area->state);
                if ($location !== null) {
                    $fallbackCandidates[strtoupper($area->state)] = $location;
                }
            }
        }

        // Emit a state-level target ONLY for states with no DMA coverage — otherwise the DMAs
        // already cover that area and adding the whole state double-counts (the NY + Philly
        // DMAs already span all of NJ).
        foreach ($fallbackCandidates as $state => $location) {
            if (! isset($dmaStates[$state])) {
                $metros[$location] ??= new Metro($this->display($location), $location, isFallback: true);
            }
        }

        $list = array_values($metros);
        usort($list, fn (Metro $a, Metro $b) => strcmp($a->name, $b->name));

        return $list;
    }

    private function dmaFor(CoverageArea $area): ?string
    {
        // Only county-subdivision GEOIDs reliably encode the county (STATE+COUNTY = 5 digits).
        if ($area->type !== MunicipalityType::CountySubdivision || strlen($area->geo_id) < 5) {
            return null;
        }

        return $this->table->dmaForCounty(substr($area->geo_id, 0, 5));
    }

    private function display(string $locationName): string
    {
        return trim((string) preg_replace('/,\s*United States$/', '', $locationName));
    }
}
