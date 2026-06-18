<?php

namespace App\Locations;

use App\Enums\MunicipalityType;

/**
 * The coverage engine's output: the per-base breakdown plus the deduplicated union (the
 * authoritative coverage set). Reports the MCD/place split so the NJ/PA correctness
 * check is one glance.
 */
final class CoverageResult
{
    /**
     * @param  list<BaseCoverage>  $perBase
     * @param  list<CoverageMunicipality>  $union
     */
    public function __construct(
        public readonly array $perBase,
        public readonly array $union,
    ) {}

    public function unionCount(): int
    {
        return count($this->union);
    }

    public function mcdCount(): int
    {
        return count(array_filter($this->union, fn (CoverageMunicipality $m) => $m->type === MunicipalityType::CountySubdivision));
    }

    public function placeCount(): int
    {
        return count(array_filter($this->union, fn (CoverageMunicipality $m) => $m->type === MunicipalityType::Place));
    }

    /** How many union municipalities are reached by more than one base (the overlap lens). */
    public function overlapCount(): int
    {
        return count(array_filter($this->union, fn (CoverageMunicipality $m) => count($m->sourceLocationIds) > 1));
    }

    /**
     * Union town counts grouped by population bucket (Large/Medium/Small/Unknown).
     *
     * @param  list<CoverageMunicipality>  $municipalities
     * @return array{large: int, medium: int, small: int, unknown: int}
     */
    public static function bucketCounts(array $municipalities): array
    {
        $counts = ['large' => 0, 'medium' => 0, 'small' => 0, 'unknown' => 0];
        $thresholds = (array) config('launchpad.locations.population_buckets', []);
        foreach ($municipalities as $m) {
            $counts[$m->bucket($thresholds)->value]++;
        }

        return $counts;
    }

    /**
     * Per-base net-new vs already-covered breakdown — overlap transparency. A municipality
     * this base reaches that ANOTHER base also reaches is "already covered" (counted once
     * in the union; never double-counted). Lets the operator see redundancy when adding a
     * location that overlaps existing coverage ("18 towns — 12 new, 6 already in X's area").
     *
     * @return list<array{location_id: string, location_name: string, total: int, new: int, shared: int, shared_with: list<string>}>
     */
    public function overlapByBase(): array
    {
        $sources = [];
        foreach ($this->union as $m) {
            $sources[$m->geoId] = $m->sourceLocationIds;
        }
        $names = [];
        foreach ($this->perBase as $b) {
            $names[$b->locationId] = $b->locationName;
        }

        $out = [];
        foreach ($this->perBase as $base) {
            $shared = 0;
            $sharedWith = [];
            $counties = [];
            $states = [];
            foreach ($base->municipalities as $m) {
                $others = array_values(array_diff($sources[$m->geoId] ?? [], [$base->locationId]));
                if ($others !== []) {
                    $shared++;
                    foreach ($others as $otherId) {
                        $sharedWith[$otherId] = $names[$otherId] ?? $otherId;
                    }
                }
                if ($m->type === MunicipalityType::CountySubdivision && strlen($m->geoId) >= 5) {
                    $counties[substr($m->geoId, 0, 5)] = true; // GEOID county FIPS (state+county)
                }
                if ($m->state !== null) {
                    $states[$m->state] = true;
                }
            }
            $total = count($base->municipalities);
            $out[] = [
                'location_id' => $base->locationId,
                'location_name' => $base->locationName,
                'total' => $total,
                'new' => $total - $shared,
                'shared' => $shared,
                'shared_with' => array_values($sharedWith),
                'counties' => count($counties),
                'states' => array_keys($states),
                'buckets' => self::bucketCounts($base->municipalities),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'union' => array_map(fn (CoverageMunicipality $m) => $m->toArray(), $this->union),
            'overlap_count' => $this->overlapCount(),
            'buckets' => self::bucketCounts($this->union),
            'overlap_by_base' => $this->overlapByBase(),
            'per_base' => array_map(fn (BaseCoverage $b) => [
                'location_id' => $b->locationId,
                'location_name' => $b->locationName,
                'radius_miles' => $b->radiusMiles,
                'municipalities' => array_map(fn (CoverageMunicipality $m) => $m->toArray(), $b->municipalities),
            ], $this->perBase),
        ];
    }
}
