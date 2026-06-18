<?php

namespace App\Integrations\Census;

/**
 * A Census county (TIGERweb layer 82). `geoId` is the 5-digit STATE+COUNTY FIPS
 * (e.g. 34013 = Essex County, NJ); `stateFips`/`countyFips` are its parts, used to
 * attribute-query the county's subdivisions and the ACS population join.
 */
final class County
{
    public function __construct(
        public readonly string $geoId,
        public readonly string $name,
        public readonly string $stateFips,
        public readonly string $countyFips,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['geo_id' => $this->geoId, 'name' => $this->name, 'state_fips' => $this->stateFips, 'county_fips' => $this->countyFips];
    }
}
