<?php

use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Operate\PagesBoard;

/**
 * The build queue offers "towns selected here with no page yet". A hub page is pinned through
 * `location_id` and carries no `geo_id`, so the GEOID join that decides "has a page" never saw it —
 * every GBP location's own town sat in its own queue asking to be built a second time.
 *
 * The hub's town is its CITY, not the polygon under its building. SPG's "Doylestown" office physically
 * stands in Plumstead township; its page is still the Doylestown page. Matching by the point would have
 * covered Plumstead and left both Doylestowns — the borough and the township, two GEOIDs, one name —
 * sitting in the queue, which is the thing that was wrong in the first place.
 */
function homeTownSite(array $locationOverrides = []): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $location = Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Doylestown office', 'lat' => 40.3101, 'lng' => -75.1299,
        // The mailing city the hub page is about; the building is elsewhere (see home_geo_id).
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Doylestown', 'short_name' => 'Doylestown'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'Pennsylvania', 'short_name' => 'PA'],
        ],
        'home_geo_id' => '4201761616',   // Plumstead township — where it actually stands
    ], $locationOverrides));

    $borough = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Doylestown', 'state' => 'PA', 'geo_id' => '4201720328',
        'population' => 8305, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);
    $township = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Doylestown', 'state' => 'PA', 'geo_id' => '4201720344',
        'population' => 17945, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);
    $plumstead = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Plumstead', 'state' => 'PA', 'geo_id' => '4201761616',
        'population' => 12442, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);
    $other = CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'New Britain', 'state' => 'PA', 'geo_id' => '4201753368',
        'population' => 2846, 'page_selected' => true, 'source_location_ids' => [$location->id],
    ]);

    return compact('site', 'location', 'borough', 'township', 'plumstead', 'other');
}

function eligibleIds(Site $site): array
{
    return collect(app(PagesBoard::class)->locations($site)['eligible'])
        ->flatten(1)->pluck('coverage_area_id')->all();
}

it('drops the town a hub page is about, and its same-name sibling, from the build queue', function () {
    $f = homeTownSite();

    $eligible = eligibleIds($f['site']);

    // Both Doylestowns: the hub page covers the NAME, and the site can only show one row for it.
    expect($eligible)->not->toContain((string) $f['borough']->id)
        ->and($eligible)->not->toContain((string) $f['township']->id)
        // Plumstead is where the building stands, not what the page is about — it still wants a page.
        ->and($eligible)->toContain((string) $f['plumstead']->id)
        // And an unrelated town is untouched: this suppresses names, not the queue.
        ->and($eligible)->toContain((string) $f['other']->id);
});

it('falls back to the location name when it carries no mailing city', function () {
    $f = homeTownSite(['address_components' => null, 'name' => 'Doylestown']);

    expect(eligibleIds($f['site']))->not->toContain((string) $f['borough']->id);
});

it('suppresses a same-name sibling of a town that already has a page', function () {
    $f = homeTownSite(['address_components' => null, 'name' => 'Somewhere Else']);

    // Nothing covers either Doylestown yet, so the operator sees both rather than losing one unannounced.
    expect(eligibleIds($f['site']))
        ->toContain((string) $f['borough']->id)
        ->toContain((string) $f['township']->id);

    // Build the borough; the township is then a second page competing for one name.
    Content::factory()->page()->published()->create([
        'site_id' => $f['site']->id, 'page_type' => PageType::Location,
        'geo_id' => '4201720328', 'slug' => 'doylestown-pa', 'title' => 'Doylestown',
    ]);

    expect(eligibleIds($f['site']))->not->toContain((string) $f['township']->id);
});
