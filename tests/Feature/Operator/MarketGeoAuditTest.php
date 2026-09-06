<?php

use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\Site;
use App\Operator\Coverage\MarketGeoAudit;
use Illuminate\Support\Facades\Artisan;

function geoLoc(Site $s, string $city, string $state): Location
{
    return Location::factory()->create([
        'site_id' => $s->id,
        'publish_held' => false,
        'served_towns' => null,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => $city, 'short_name' => $city],
            ['types' => ['administrative_area_level_1'], 'long_name' => $state, 'short_name' => $state],
        ],
    ]);
}

function geoMkt(Site $s, string $name, string $region, ?float $lat, ?float $lng, ?string $geoId = null): Market
{
    return Market::factory()->create([
        'site_id' => $s->id, 'name' => $name, 'region' => $region, 'tier' => 'coverage',
        'lat' => $lat, 'lng' => $lng, 'geo_id' => $geoId,
    ]);
}

it('a real place with a Census geo_id is never a delete candidate, even without a Location match', function () {
    $site = Site::factory()->create();
    // No Location for this site; valid US geo; carries a geo_id → authoritative proof it's real.
    geoMkt($site, 'Marshall', 'MD', 39.63, -76.49, '2451234');

    // geo_id confirms a real enumerated place → not surfaced at all.
    expect(app(MarketGeoAudit::class)->suspects($site))->toBe([]);
});

it('flags a "N, " numbering artifact as a rename (not delete) on a real geo_id market', function () {
    $site = Site::factory()->create();
    $rows = app(MarketGeoAudit::class)->suspects(
        tap($site, fn ($s) => geoMkt($s, '1, Abingdon', 'MD', 39.40, -76.29, '2400100'))
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name_artifact'])->toBeTrue()
        ->and($rows[0]['geo_id'])->toBe('2400100')
        ->and($rows[0]['advisory'])->toContain('rename')
        ->and($rows[0]['advisory'])->not->toContain('delete');
});

it('flags a market with no geo_id, no Location, and no dependents as a delete candidate', function () {
    $site = Site::factory()->create();
    geoMkt($site, 'Nowhere', 'ZZ', 40.0, -75.0, null); // valid geo, but nothing confirms it is real

    $rows = app(MarketGeoAudit::class)->suspects($site);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['geo_id'])->toBeNull()
        ->and($rows[0]['location_match'])->toBeFalse()
        ->and($rows[0]['advisory'])->toContain('delete candidate');
});

it('an out-of-area coordinate on a real geo_id market reads as repair-geo', function () {
    $site = Site::factory()->create();
    geoMkt($site, 'Fallston', 'MD', -29.62, -175.45, '2426000'); // ocean coord, but a real place

    $rows = app(MarketGeoAudit::class)->suspects($site);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['geo'])->toBe('out_of_area')
        ->and($rows[0]['advisory'])->toContain('repair geo');
});

it('a Location match still confirms a market that lacks a geo_id', function () {
    $site = Site::factory()->create();
    geoLoc($site, 'Fallston', 'MD');
    geoMkt($site, 'Fallston', 'MD', 39.51, -76.35, null); // no geo_id but matches a Location → real

    expect(app(MarketGeoAudit::class)->suspects($site))->toBe([]);
});

it('unions HELD Locations too — a seasoning market is confirmed, not flagged', function () {
    $site = Site::factory()->create();
    // A HELD (publish_held) Location — the SPG "seasoning" case (Fallston/MD, later NY/CT).
    Location::factory()->create([
        'site_id' => $site->id, 'publish_held' => true, 'served_towns' => null,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Fallston', 'short_name' => 'Fallston'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'MD', 'short_name' => 'MD'],
        ],
    ]);
    // No geo_id — ONLY the held Location can confirm it. If the union excluded held Locations, this would
    // wrongly surface as a delete candidate on the very first run.
    geoMkt($site, 'Fallston', 'MD', 39.51, -76.35, null);

    expect(app(MarketGeoAudit::class)->suspects($site))->toBe([]);
});

it('matches a market against a county-qualified served town ("Marshall (Harford)")', function () {
    $site = Site::factory()->create();
    // Fallston serves Marshall, stored county-qualified because "Marshall" duplicates across MD counties
    // (exactly what SeedServedTownsCommand writes). Before the trailing-parenthetical strip this read
    // "NO match" for precisely the duplicated towns where mis-assignment happens.
    Location::factory()->create([
        'site_id' => $site->id, 'publish_held' => true,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Fallston', 'short_name' => 'Fallston'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'MD', 'short_name' => 'MD'],
        ],
        'served_towns' => [
            ['name' => 'Marshall (Harford)', 'state' => 'MD', 'lat' => 39.63, 'lng' => -76.49, 'geocoded' => true],
        ],
    ]);
    // The market carries the bare town name and no geo_id — ONLY the served-town heuristic can confirm it.
    geoMkt($site, 'Marshall', 'MD', 39.63, -76.49, null);

    // Confirmed real via the qualified served town → not surfaced as a suspect.
    expect(app(MarketGeoAudit::class)->suspects($site))->toBe([]);
});

it('still matches a numbered market against a county-qualified served town', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'publish_held' => true,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Fallston', 'short_name' => 'Fallston'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'MD', 'short_name' => 'MD'],
        ],
        'served_towns' => [
            ['name' => 'Marshall (Harford)', 'state' => 'MD', 'lat' => 39.63, 'lng' => -76.49, 'geocoded' => true],
        ],
    ]);
    // Both defects at once: the "N, " artifact on the market AND the county qualifier on the served town.
    // The name is stripped on the market side and the qualifier on the Location side → they still meet.
    $rows = app(MarketGeoAudit::class)->suspects(
        tap($site, fn ($s) => geoMkt($s, '1, Marshall', 'MD', 39.63, -76.49, null))
    );

    // Surfaced only for the rename (artifact), and confirmed a real place via the Location match.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name_artifact'])->toBeTrue()
        ->and($rows[0]['location_match'])->toBeTrue()
        ->and($rows[0]['advisory'])->toContain('rename');
});

it('marks a market with dependents as review, never a blind delete', function () {
    $site = Site::factory()->create();
    $m = geoMkt($site, 'Ghost', 'NJ', 40.7, -74.1, null); // no geo_id, no Location, but has a pinned keyword
    Keyword::withoutGlobalScopes()->forceCreate([
        'site_id' => $site->id, 'market_id' => $m->id, 'query' => 'x', 'source' => 'seed', 'status' => 'scored',
    ]);

    $rows = app(MarketGeoAudit::class)->suspects($site);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['dependents']['keywords'])->toBe(1)
        ->and($rows[0]['advisory'])->toContain('has dependents');
});

it('reports "no suspects" as a real clean result, not a non-detection', function () {
    $site = Site::factory()->create(['brand_name' => 'CleanCo']);
    geoMkt($site, 'Abingdon', 'MD', 39.40, -76.29, '2400100'); // valid geo + geo_id → clean

    expect(app(MarketGeoAudit::class)->suspects($site))->toBe([]);

    $code = Artisan::call('launchpad:report-market-geo', ['--site' => $site->id]);
    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('No suspect markets');
});

it('the report command runs read-only and changes nothing', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    geoMkt($site, 'Nowhere', 'ZZ', 40.0, -75.0, null);
    $before = Market::withoutGlobalScopes()->count();

    $code = Artisan::call('launchpad:report-market-geo', ['--site' => $site->id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('Nowhere')
        ->and($out)->toContain('delete candidate')
        ->and(Market::withoutGlobalScopes()->count())->toBe($before);
});
