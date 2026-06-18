<?php

namespace App\Integrations\Census;

/**
 * A canned gazetteer for tests: returns a fixed list of candidate municipalities for
 * every call (the coverage engine still applies the real Haversine filter + union, so
 * the list may include out-of-range items to exercise that filter). Records the points
 * it was queried with.
 */
class MockMunicipalityGazetteer implements MunicipalityGazetteer
{
    /** @var list<array{lat: float, lng: float, radius: float}> */
    public array $queries = [];

    /**
     * @param  list<Municipality>  $municipalities
     * @param  list<County>  $counties  returned by countiesInState/countyAt
     * @param  array<string, list<Municipality>>  $subdivisions  "stateFips:countyFips" => municipalities
     */
    public function __construct(
        private readonly array $municipalities = [],
        private readonly array $counties = [],
        private readonly array $subdivisions = [],
    ) {}

    /**
     * @return list<Municipality>
     */
    public function near(float $lat, float $lng, float $radiusMiles): array
    {
        $this->queries[] = ['lat' => $lat, 'lng' => $lng, 'radius' => $radiusMiles];

        return $this->municipalities;
    }

    public function countyAt(float $lat, float $lng): ?County
    {
        return $this->counties[0] ?? null;
    }

    /**
     * @return list<County>
     */
    public function countiesInState(string $stateFips): array
    {
        return array_values(array_filter($this->counties, fn (County $c) => $c->stateFips === $stateFips));
    }

    /**
     * @return list<Municipality>
     */
    public function subdivisionsInCounty(string $stateFips, string $countyFips): array
    {
        return $this->subdivisions["{$stateFips}:{$countyFips}"] ?? [];
    }

    /**
     * @return list<Municipality>
     */
    public function byName(string $query): array
    {
        $query = trim(strtolower($query));
        if ($query === '') {
            return [];
        }

        return array_values(array_filter(
            $this->municipalities,
            fn (Municipality $m) => str_contains(strtolower($m->name), $query),
        ));
    }
}
