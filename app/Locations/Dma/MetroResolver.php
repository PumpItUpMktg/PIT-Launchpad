<?php

namespace App\Locations\Dma;

use App\Enums\MunicipalityType;
use App\Models\CoverageArea;

/**
 * Resolves the distinct set of covered metros from a site's coverage municipalities:
 * each county subdivision's GEOID encodes its county (state+county = first 5 digits) →
 * Nielsen DMA → DataForSEO location_name; deduped. Where a county doesn't map (or a row
 * is a place, whose GEOID doesn't encode a county), a state-level location is the
 * fallback so every covered state still yields a query. The radius already crossed
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

        foreach ($coverage as $area) {
            $dma = $this->dmaFor($area);
            if ($dma !== null) {
                $metros[$dma] ??= new Metro($this->display($dma), $dma);

                continue;
            }

            // Fallback: the covered state.
            $state = $area->state === null ? null : $this->table->locationForState($area->state);
            if ($state !== null) {
                $metros[$state] ??= new Metro($this->display($state), $state, isFallback: true);
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
