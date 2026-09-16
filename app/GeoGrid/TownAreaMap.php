<?php

namespace App\GeoGrid;

use App\Integrations\Census\TigerwebGazetteer;
use App\Models\JobCounty;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\TownRank\ServiceAreas;

/**
 * The drawing frame for one service area — the geometry every town map in the app shares, built once and
 * handed to whatever wants to colour it.
 *
 * A location's served counties give the outer outline; each served town gives its own boundary; the frame
 * is the union of both plus the town centres, projected into a 0–100 SVG box with longitude corrected by
 * cos(latitude) so a county keeps its shape. Consumers supply only the colour and the number per town:
 * the website's organic rank ({@see ServiceAreas}) or the GBP map pack
 * ({@see GeoGridBoard}). One projector, one set of paths, so the same town lands on the same spot on
 * every map an operator compares side by side.
 */
final class TownAreaMap
{
    public function __construct(
        private readonly CoverageGrid $coverage,
        private readonly CountyOutlines $outlines,
        private readonly TownOutlines $townOutlines,
    ) {}

    /**
     * @return array{
     *     towns: list<array{coverage_area_id: string, geo_id: string, label: string, lat: float, lng: float, population: int}>,
     *     coords: array<string, array{lat: float, lng: float}>,
     *     town_paths: array<string, list<string>>,
     *     outlines: list<array{geoid: string, label: string, paths: list<string>}>,
     *     counties: list<array{geoid: string, label: string}>,
     *     project: callable(float, float): array{float, float}
     * }
     */
    public function for(Location $location): array
    {
        $towns = $this->coverage->pointsFor($location);
        $coords = [];
        $townGeoIds = [];
        foreach ($towns as $town) {
            $id = (string) $town['coverage_area_id'];
            $coords[$id] = ['lat' => $town['lat'], 'lng' => $town['lng']];
            $geoId = trim($town['geo_id']);
            if ($geoId !== '') {
                $townGeoIds[$id] = $geoId;
            }
        }

        $countyIds = $this->countyGeoIds($location);
        $countyRings = $this->outlines->for($countyIds);
        $townRings = $this->townOutlines->for(array_values($townGeoIds));

        $project = MapProjection::projector(MapProjection::unionExtent([
            MapProjection::ringsExtent($countyRings),
            MapProjection::ringsExtent($townRings),
            MapProjection::pointsExtent($coords),
        ]));

        $townPaths = [];
        foreach ($townGeoIds as $id => $geoId) {
            if (isset($townRings[$geoId])) {
                $townPaths[(string) $id] = MapProjection::paths($townRings[$geoId], $project);
            }
        }

        $labels = $this->countyLabels($countyIds);
        $outlines = [];
        foreach ($countyRings as $geoId => $rings) {
            $outlines[] = ['geoid' => (string) $geoId, 'label' => $labels[$geoId] ?? "County {$geoId}", 'paths' => MapProjection::paths($rings, $project)];
        }

        return [
            'towns' => $towns,
            'coords' => $coords,
            'town_paths' => $townPaths,
            'outlines' => $outlines,
            'counties' => array_map(fn (string $g): array => ['geoid' => $g, 'label' => $labels[$g] ?? "County {$g}"], $countyIds),
            'project' => $project,
        ];
    }

    /** The counties this location serves: its home county plus any it was given. @return list<string> */
    public function countyGeoIds(Location $location): array
    {
        return collect([$location->home_county_geoid])
            ->merge(is_array($location->county_geoids) ? $location->county_geoids : [])
            ->map(fn ($g): string => trim((string) $g))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * "Warren County, NJ" per GEOID: from the county registry when it has the county, else the Census name
     * that came with the county's outline plus the state read off the GEOID; a county neither knows keeps
     * its GEOID as the label.
     *
     * @param  list<string>  $geoids
     * @return array<string, string>
     */
    public function countyLabels(array $geoids): array
    {
        if ($geoids === []) {
            return [];
        }
        $labels = [];
        foreach (JobCounty::query()->withoutGlobalScope(SiteScope::class)->whereIn('county_geoid', $geoids)->get() as $county) {
            $labels[(string) $county->county_geoid] = self::countyLabel((string) $county->name, $county->state);
        }
        $missing = array_values(array_filter($geoids, fn (string $g): bool => ! isset($labels[$g])));
        if ($missing !== []) {
            foreach ($this->outlines->names($missing) as $geoId => $name) {
                $labels[(string) $geoId] = self::countyLabel($name, TigerwebGazetteer::stateForFips((string) $geoId));
            }
        }

        return $labels;
    }

    private static function countyLabel(string $name, ?string $state): string
    {
        $name = trim($name);
        if (! preg_match('/county|parish|borough|census area|municipio/i', $name)) {
            $name .= ' County';
        }

        return $name.($state !== null && $state !== '' ? ", {$state}" : '');
    }
}
