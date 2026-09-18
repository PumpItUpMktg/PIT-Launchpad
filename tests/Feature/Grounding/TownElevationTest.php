<?php

use App\Local\Grounding\TownElevationFacts;
use App\Local\Grounding\TownElevationSync;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownElevation;
use Illuminate\Support\Facades\Http;

/** The USGS point service's answer, shaped as the live service returns it (verified against Doylestown). */
function usgsFeet(float $feet): array
{
    return ['location' => ['x' => -75.13, 'y' => 40.31], 'locationId' => 0, 'value' => $feet, 'rasterId' => 48146, 'resolution' => 1];
}

function elevationSite(array $towns): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.3, 'lng' => -75.1]);
    foreach ($towns as $name => [$geoId, $feet]) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => $geoId, 'name' => $name,
            'lat' => 40.3, 'lng' => -75.1, 'population' => 10000, 'source_location_ids' => [$loc->id]]);
        if ($feet !== null) {
            TownElevation::query()->create(['geo_id' => $geoId, 'name' => $name, 'state' => 'PA', 'elevation_ft' => $feet]);
        }
    }

    return [$site, $loc];
}

it('measures a town once and skips it on the next pass', function () {
    Http::fake(['*epqs.nationalmap.gov*' => Http::response(usgsFeet(416.9673001802235))]);
    [$site] = elevationSite(['Warrington' => ['4201781048', null]]);

    $result = app(TownElevationSync::class)->forSite($site);

    expect($result)->toMatchArray(['fetched' => 1, 'measured' => 1, 'unknown' => 0])
        ->and((float) TownElevation::query()->where('geo_id', '4201781048')->value('elevation_ft'))->toBe(417.0);

    Http::fake();
    expect(app(TownElevationSync::class)->forSite($site))->toMatchArray(['fetched' => 0, 'outstanding' => 0]);
    Http::assertNothingSent();
});

it('records a town with no value rather than asking about it forever', function () {
    // The service answers with a large negative sentinel where it has no data.
    Http::fake(['*epqs.nationalmap.gov*' => Http::response(usgsFeet(-999999.0))]);
    [$site] = elevationSite(['Offshore' => ['4201700001', null]]);

    expect(app(TownElevationSync::class)->forSite($site))->toMatchArray(['fetched' => 1, 'measured' => 0, 'unknown' => 1]);

    $row = TownElevation::query()->where('geo_id', '4201700001')->sole();
    expect($row->elevation_ft)->toBeNull()          // never stored as a depth below sea level
        ->and($row->fetched_at)->not->toBeNull();   // but asked, so the next pass moves on
});

it('speaks only at the ends of the local range, and says what the middle of the area is', function () {
    [$site] = elevationSite([
        'Riverside' => ['4201700010', 90.0],    // lowest
        'Midvale' => ['4201700011', 300.0],
        'Centerton' => ['4201700012', 310.0],
        'Middleford' => ['4201700013', 320.0],
        'Hilltop' => ['4201700014', 620.0],     // highest
    ]);
    $facts = app(TownElevationFacts::class);

    expect($facts->for($facts->row('4201700010'), (string) $site->id))
        ->toBe(['Riverside is among the lower-lying towns in this service area — about 90 feet, where the middle of the area sits nearer 310.']);
    expect($facts->for($facts->row('4201700014'), (string) $site->id))
        ->toBe(['Hilltop sits high for this service area — about 620 feet, where the middle of the area sits nearer 310.']);

    // Mid-range: a number with no information in it, so nothing is said.
    expect($facts->for($facts->row('4201700011'), (string) $site->id))->toBe([]);
});

it('says nothing without a range to read the town against', function () {
    [$site] = elevationSite(['Riverside' => ['4201700010', 90.0], 'Hilltop' => ['4201700014', 620.0]]);
    $facts = app(TownElevationFacts::class);

    // Two towns is not a local range — a "low for the area" claim needs an area.
    expect($facts->for($facts->row('4201700010'), (string) $site->id))->toBe([])
        // And with no site there is nothing to be relative to.
        ->and($facts->for($facts->row('4201700010'), null))->toBe([]);
});
