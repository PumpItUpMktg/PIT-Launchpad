<?php

use App\Enums\PageType;
use App\Enums\UserRole;
use App\Filament\Pages\ServiceAreasPage;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\RunCoverageScan;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(ServiceAreasPage::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect(ServiceAreasPage::canAccess())->toBeTrue();
});

it('lists the service areas, opens one to a card per keyword with both maps, and shows the GBP placeholder where no scan exists', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
        '3404128590' => [[['lat' => 40.87, 'lng' => -74.85], ['lat' => 40.87, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.85]]],
    ]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => []]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $kw2 = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'mold remediation', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 1, 'found_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'q', 'rank' => 2, 'collected_at' => now()]);
    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.85, 'lng' => -74.83, 'rank' => 1, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'collected_at' => now()]);

    Livewire::test(ServiceAreasPage::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Hackettstown office')
        ->assertSee('Warren County, NJ')
        ->assertSee('1 towns')
        ->assertDontSee('Google Business Profile')
        ->call('openArea', $loc->id)
        ->assertSet('locationId', $loc->id)
        ->assertSee('All service areas')
        ->assertSee('sump pump service')
        ->assertSee('mold remediation')
        ->assertSee('Google Business Profile')
        ->assertSee('Area score')
        ->assertSee('No GBP coverage scan for this keyword here yet')          // mold remediation's GBP column
        ->assertSee('No Town Rank scan for this keyword yet')                  // mold remediation's website column
        ->assertSeeHtml('aria-label="GBP map-pack rank by town"')              // sump pump service has both maps
        ->assertSeeHtml('class="s-county"')                                     // the county outline under the maps
        ->assertDontSee('What to do')
        // A dot on either map selects its town: the Town Rank town detail opens under that card.
        ->assertSeeHtml('wire:click="selectTown(\''.$kw->id.'\', \''.$hack->id.'\')"')
        ->call('selectTown', $kw->id, $hack->id)
        ->assertSet('keywordId', $kw->id)
        ->assertSet('townId', $hack->id)
        ->assertSee('Hackettstown, NJ')
        ->assertSee('What to do')
        ->assertSee('GBP map pack')
        ->assertSeeHtml('class="s-shape sel"')   // the selected town's shape is outlined (it has a boundary, so no dot)
        ->assertSeeHtml('class="s-rank"')        // and the map-pack position is written into the town it was found in
        // The same dot again closes it.
        ->call('selectTown', $kw->id, $hack->id)
        ->assertSet('townId', null)
        ->assertDontSee('What to do')
        ->call('closeArea')
        ->assertSet('locationId', null)
        ->assertDontSee('Google Business Profile');
});

it('queues a GBP report (coverage scan) for the area\'s location × keyword from the card, and refuses while one is collecting', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer);
    config(['launchpad.geo_grid.cost_per_request' => 0.002]);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => []]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mansfield', 'state' => 'NJ', 'geo_id' => '3404143440', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => []]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);

    $page = Livewire::test(ServiceAreasPage::class)->set('siteId', $site->id)->call('openArea', $loc->id)
        ->assertSee('Run GBP report')
        ->assertSee('2 requests · ~$0.00');   // 2 towns × $0.002, shown to the cent

    $page->call('runGbp', $kw->id);
    Queue::assertPushed(RunCoverageScan::class, fn (RunCoverageScan $j): bool => $j->locationId === (string) $loc->id && $j->keywordId === (string) $kw->id);

    // A coverage scan now collecting: the button reads Collecting… and a second click queues nothing more.
    GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 2, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'pending', 'scanned_at' => now()]);
    Livewire::test(ServiceAreasPage::class)->set('siteId', $site->id)->call('openArea', $loc->id)
        ->assertSee('Collecting…')
        ->call('runGbp', $kw->id);
    Queue::assertPushed(RunCoverageScan::class, 1);
});

/**
 * Rank and coverage are two independent facts about a town, and the map has to carry both: the fill says
 * where we stand, the hatch says whether a page exists there at all. A town can rank nowhere BECAUSE it
 * has no page, and colour alone can never show that — every unranked town is the same grey whether it is
 * a gap in the plan or a page that is losing.
 */
it('hatches the towns that have a page and says so on the ones that do not', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
        '3404128590' => [[['lat' => 40.87, 'lng' => -74.85], ['lat' => 40.87, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.85]]],
        '3404128591' => [[['lat' => 40.92, 'lng' => -74.90], ['lat' => 40.92, 'lng' => -74.86], ['lat' => 40.88, 'lng' => -74.86], ['lat' => 40.88, 'lng' => -74.90]]],
    ]));

    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);

    $covered = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => []]);
    $bare = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Allamuchy', 'state' => 'NJ', 'geo_id' => '3404128591', 'population' => 4000, 'lat' => 40.90, 'lng' => -74.88, 'source_location_ids' => []]);

    // Only the first town has a published page — matched on GEOID, the anchor the whole chain now uses.
    Content::factory()->page()->published()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location,
        'geo_id' => '3404128590', 'slug' => 'hackettstown-nj', 'title' => 'Hackettstown, NJ',
    ]);

    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 2, 'found_count' => 1, 'scanned_at' => now()]);
    foreach ([[$covered, 'Hackettstown', 40.85, -74.83, 2], [$bare, 'Allamuchy', 40.90, -74.88, null]] as [$area, $label, $lat, $lng, $rank]) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $area->id,
            'label' => $label, 'state' => 'NJ', 'lat' => $lat, 'lng' => $lng, 'query' => 'q', 'rank' => $rank, 'collected_at' => now()]);
    }

    $html = Livewire::test(ServiceAreasPage::class)
        ->set('siteId', $site->id)
        ->call('openArea', $loc->id)
        ->assertOk()
        // The hatch is defined once per map and keyed to its card, so two cards on a page cannot collide.
        ->assertSeeHtml('<pattern id="hp-web-'.$kw->id.'"')
        ->assertSeeHtml('fill="url(#hp-web-'.$kw->id.')"')
        // Hover tells the truth even where the hatch is too small to read.
        ->assertSeeHtml('Allamuchy, NJ — not found · no page')
        ->assertDontSeeHtml('Hackettstown, NJ — #2 · no page')
        ->html();

    // Exactly one town is hatched: the one with a page. The overlay never swallows the click that
    // selects a town, so the shape beneath it stays the only interactive element.
    expect(substr_count($html, 'class="s-haspage"'))->toBe(1)
        ->and($html)->toContain('.sva .s-haspage { pointer-events:none; }');
});

/**
 * A rank with no date beside it reads as current, and each lane is scanned on its own schedule — the
 * website town search, the "searched from town" pass and the GBP map pack can be days apart for one
 * town. The detail panel dates each lane separately, from the POINT's own collected_at rather than the
 * scan's start, because a scan collects incrementally and a town can be read hours after it began.
 */
it('dates each lane in the town detail from that town own reading', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
        '3404128590' => [[['lat' => 40.87, 'lng' => -74.85], ['lat' => 40.87, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.85]]],
    ]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'geo_id' => '3404128590', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => []]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);

    // The website read is four days old; the map-pack read is an hour old. Same town, same keyword.
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 1, 'found_count' => 1, 'scanned_at' => now()->subDays(4)]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'q', 'rank' => 2, 'collected_at' => now()->subDays(4)]);

    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()->subDays(4)]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.85, 'lng' => -74.83, 'rank' => 1, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'collected_at' => now()->subHour()]);

    Livewire::test(ServiceAreasPage::class)
        ->set('siteId', $site->id)
        ->call('openArea', $loc->id)
        ->call('selectTown', $kw->id, $hack->id)
        ->assertOk()
        ->assertSee('Town search')
        ->assertSee('4 days ago')       // the website reading
        ->assertSee('1 hour ago')       // the map-pack reading, from the point rather than the scan
        // The lane nobody scanned says so, rather than showing a blank that reads as current.
        ->assertSee('never measured');
});
