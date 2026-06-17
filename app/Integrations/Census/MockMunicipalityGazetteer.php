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
     */
    public function __construct(private readonly array $municipalities = []) {}

    /**
     * @return list<Municipality>
     */
    public function near(float $lat, float $lng, float $radiusMiles): array
    {
        $this->queries[] = ['lat' => $lat, 'lng' => $lng, 'radius' => $radiusMiles];

        return $this->municipalities;
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
