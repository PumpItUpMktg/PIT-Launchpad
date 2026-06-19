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

test('the state fallback is suppressed when DMAs already cover that state (no double-count)', function () {
    $site = Site::factory()->create();
    area($site, '3400312345', MunicipalityType::CountySubdivision);  // county 34003 → NY DMA (an NJ county)
    area($site, '3400599999', MunicipalityType::CountySubdivision);  // county 34005 → Philadelphia DMA (an NJ county)
    area($site, '3445000', MunicipalityType::Place);                 // an NJ place → would fall back to NJ state
    area($site, '3499912345', MunicipalityType::CountySubdivision);  // an unmapped NJ county → would fall back to NJ state

    $metros = resolver()->forCoverage(CoverageArea::all());

    // NJ is fully covered by the NY + Philadelphia DMAs → no "New Jersey (state)" target
    expect(collect($metros)->pluck('locationName'))->not->toContain('New Jersey,United States')
        ->and(collect($metros)->pluck('locationName'))->toContain('New York,NY,United States', 'Philadelphia,PA,United States')
        ->and($metros)->toHaveCount(2);
});

test('a state with no DMA coverage still gets its state-level fallback', function () {
    $site = Site::factory()->create();
    area($site, '3400312345', MunicipalityType::CountySubdivision, state: 'NJ'); // NJ → NY DMA
    area($site, '0645000', MunicipalityType::Place, state: 'CA');                // CA place, no CA DMA mapped

    $resolver = new MetroResolver(new DmaTable(
        countyToDma: ['34003' => 'New York,NY,United States'],
        stateToLocation: ['NJ' => 'New Jersey,United States', 'CA' => 'California,United States'],
    ));
    $metros = $resolver->forCoverage(CoverageArea::all());

    expect(collect($metros)->pluck('locationName'))->toContain('New York,NY,United States', 'California,United States')
        ->and(collect($metros)->pluck('locationName'))->not->toContain('New Jersey,United States'); // NJ covered by the DMA
});
