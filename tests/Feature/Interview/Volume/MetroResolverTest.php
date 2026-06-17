<?php

use App\Enums\MunicipalityType;
use App\Locations\Dma\DmaTable;
use App\Locations\Dma\MetroResolver;
use App\Models\CoverageArea;
use App\Models\Site;

function resolver(): MetroResolver
{
    return new MetroResolver(new DmaTable(
        countyToDma: ['34003' => 'New York,NY,United States', '34005' => 'Philadelphia,PA,United States'],
        stateToLocation: ['NJ' => 'New Jersey,United States'],
    ));
}

function area(Site $site, string $geoId, MunicipalityType $type, ?string $state = 'NJ'): CoverageArea
{
    return CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => $geoId, 'type' => $type, 'state' => $state]);
}

test('it maps county-subdivision GEOIDs to DMAs and dedupes', function () {
    $site = Site::factory()->create();
    area($site, '3400312345', MunicipalityType::CountySubdivision); // county 34003 → NY
    area($site, '3400354321', MunicipalityType::CountySubdivision); // county 34003 → NY (dupe)
    area($site, '3400599999', MunicipalityType::CountySubdivision); // county 34005 → Philadelphia

    $metros = resolver()->forCoverage(CoverageArea::all());

    expect($metros)->toHaveCount(2)
        ->and(collect($metros)->pluck('locationName'))->toContain('New York,NY,United States')
        ->and(collect($metros)->pluck('locationName'))->toContain('Philadelphia,PA,United States')
        ->and(collect($metros)->firstWhere('locationName', 'New York,NY,United States')->name)->toBe('New York,NY');
});

test('a place (no county in its GEOID) falls back to the state location', function () {
    $site = Site::factory()->create();
    area($site, '3445000', MunicipalityType::Place); // place → no county → NJ state fallback

    $metros = resolver()->forCoverage(CoverageArea::all());

    expect($metros)->toHaveCount(1)
        ->and($metros[0]->locationName)->toBe('New Jersey,United States')
        ->and($metros[0]->isFallback)->toBeTrue();
});

test('an unmapped county falls back to its state', function () {
    $site = Site::factory()->create();
    area($site, '3499912345', MunicipalityType::CountySubdivision); // county 34999 not in table → NJ fallback

    $metros = resolver()->forCoverage(CoverageArea::all());

    expect($metros)->toHaveCount(1)
        ->and($metros[0]->locationName)->toBe('New Jersey,United States');
});
