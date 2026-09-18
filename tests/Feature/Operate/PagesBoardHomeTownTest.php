<?php

use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Operate\PagesBoard;

/**
 * The build queue offers "towns selected here with no page yet". Two things used to make it lie about
 * the town a location is standing in.
 *
 * A hub page is pinned through `location_id` and carries no `geo_id`, so the GEOID join that decides
 * "has a page" never saw it — every GBP location's own town sat in its own queue asking to be built a
 * second time. And Doylestown borough and Doylestown township are two real municipalities with two
 * GEOIDs and one name, so the queue listed "Doylestown, PA" twice with nothing to tell them apart.
 */
function homeTownSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    // The office stands in the BOROUGH — resolved from its coordinates, not its name.
    $location = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.3101, 'lng' => -75.1299,
        'home_geo_id' => '4201720328',
    ]);

    $borough = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Doylestown', 'state' => 'PA', 'geo_id' => '4201720328',
        'population' => 8305, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);
    $township = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Doylestown', 'state' => 'PA', 'geo_id' => '4201720344',
        'population' => 17945, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);
    $other = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'New Britain', 'state' => 'PA', 'geo_id' => '4201753368',
        'population' => 2846, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);

    return compact('site', 'location', 'borough', 'township', 'other');
}

function eligibleIds(Site $site): array
{
    return collect(app(PagesBoard::class)->locations($site)['eligible'])
        ->flatten(1)->pluck('coverage_area_id')->all();
}

it('drops the town a location stands in, and its same-name sibling, from the build queue', function () {
    $f = homeTownSite();

    $eligible = eligibleIds($f['site']);

    // The office's own municipality: its hub page IS that town's page.
    expect($eligible)->not->toContain((string) $f['borough']->id)
        // The township shares the name, so a second page could only compete with the hub for it.
        ->and($eligible)->not->toContain((string) $f['township']->id)
        // A genuinely different town is untouched — this suppresses names, not the queue.
        ->and($eligible)->toContain((string) $f['other']->id);
});

it('still offers both same-name towns while neither has a page', function () {
    $f = homeTownSite();
    // No anchor yet — the backfill has not run, so nothing claims either Doylestown.
    $f['location']->forceFill(['home_geo_id' => null])->save();

    $eligible = eligibleIds($f['site']);

    // Suppression only ever fires against a municipality that HAS a page. Neither does, so the operator
    // still sees both rather than silently losing one.
    expect($eligible)->toContain((string) $f['borough']->id)
        ->and($eligible)->toContain((string) $f['township']->id);
});

it('counts a location home town as covered even when it was never selected', function () {
    $f = homeTownSite();
    $f['borough']->forceFill(['page_selected' => false])->save();

    // Deselecting the borough must not resurrect the township: the hub still stands in Doylestown.
    expect(eligibleIds($f['site']))->not->toContain((string) $f['township']->id);
});
