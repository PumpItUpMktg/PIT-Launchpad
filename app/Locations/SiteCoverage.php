<?php

namespace App\Locations;

use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * A site's whole coverage: every base enumerated by ITS territory mode — the county engine for a
 * county-mode location, the proximity (distance-ring) engine for a proximity one — unioned and
 * GEOID-deduped across both, so a site can mix modes (a county-drawn office and a 15-mile shop). The
 * one entry point the workspace, the console, and the writer path use.
 */
final class SiteCoverage
{
    public function __construct(
        private readonly CountyCoverage $county,
        private readonly LocationCoverage $proximity,
    ) {}

    public function coverage(Site $site): CoverageResult
    {
        $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
        [$byDistance, $byCounty] = $locations->partition(fn (Location $l): bool => $l->isProximity());

        $results = [];
        if ($byCounty->isNotEmpty()) {
            $results[] = $this->county->coverage($site, $byCounty->values());
        }
        if ($byDistance->isNotEmpty()) {
            $results[] = $this->proximity->coverage($site, null, $byDistance->values());
        }

        return self::merge($results);
    }

    /**
     * @param  list<CoverageResult>  $results
     */
    public static function merge(array $results): CoverageResult
    {
        if (count($results) === 1) {
            return $results[0];
        }

        $perBase = [];
        /** @var array<string, CoverageMunicipality> $union */
        $union = [];
        foreach ($results as $result) {
            $perBase = [...$perBase, ...$result->perBase];
            foreach ($result->union as $m) {
                if (! isset($union[$m->geoId])) {
                    $union[$m->geoId] = $m;

                    continue;
                }
                foreach ($m->sourceLocationIds as $locationId) {
                    $union[$m->geoId] = $union[$m->geoId]->mergedWith($locationId, $m->distanceMiles, $m->manual);
                }
            }
        }

        $unionList = array_values($union);
        usort($unionList, fn (CoverageMunicipality $a, CoverageMunicipality $b) => strcmp($a->name, $b->name));

        return new CoverageResult($perBase, $unionList);
    }
}
