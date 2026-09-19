<?php

use App\Enums\UserRole;
use App\Filament\Pages\ServiceAreasPage;
use App\Filament\Pages\TownRankPage;
use App\GeoGrid\CoverageRunAll;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\RunCoverageScan;
use App\Jobs\RunTownRankKeyword;
use App\Models\CoverageArea;
use App\Models\GeoGridScan;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Models\User;
use App\TownRank\TownRankRunAll;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

function runAllFixture(int $keywords = 3): array
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
    ]));
    $site = Site::factory()->create(['domain_url' => 'https://spg.com', 'brand_name' => 'SPG']);
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);

    foreach ([['Hackettstown', '3404128590', 40.85, -74.83], ['Allamuchy', '3404128591', 40.90, -74.88]] as [$name, $geo, $lat, $lng]) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geo,
            'population' => 9000, 'lat' => $lat, 'lng' => $lng, 'source_location_ids' => [$loc->id]]);
    }

    $kws = [];
    for ($i = 0; $i < $keywords; $i++) {
        $kws[] = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service '.$i, 'track_town_rank' => true]);
    }

    return ['site' => $site, 'location' => $loc, 'keywords' => $kws];
}

/**
 * The number beside the button and the number the run posts come from one plan(). A button that quotes
 * one figure and spends another is worse than no figure at all — this is real money per click.
 */
it('quotes the same request count it posts', function () {
    Queue::fake();
    $f = runAllFixture(3);

    $plan = app(CoverageRunAll::class)->plan($f['site'], $f['location']);
    expect($plan['requests'])->toBe($plan['towns'] * 3)
        ->and($plan['cost'])->toBe(round($plan['requests'] * 0.002, 2));

    $result = app(CoverageRunAll::class)->run($f['site'], $f['location']);
    expect($result['requests'])->toBe($plan['requests'])
        ->and($result['queued'])->toBe(3);
    Queue::assertPushed(RunCoverageScan::class, 3);
});

/** A keyword already collecting is excluded from BOTH the quote and the spend — never bought twice. */
it('leaves a keyword that is already collecting out of the run', function () {
    Queue::fake();
    $f = runAllFixture(3);
    GeoGridScan::create([
        'site_id' => $f['site']->id, 'location_id' => $f['location']->id, 'keyword_id' => $f['keywords'][0]->id,
        'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0,
        'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20,
        'status' => 'pending', 'scanned_at' => now(),
    ]);

    $plan = app(CoverageRunAll::class)->plan($f['site'], $f['location']);
    expect($plan['tracked'])->toBe(3)
        ->and($plan['pending'])->toBe(1)
        ->and(count($plan['keywords']))->toBe(2);

    app(CoverageRunAll::class)->run($f['site'], $f['location']);
    Queue::assertPushed(RunCoverageScan::class, 2);
});

/** Over the ceiling the WHOLE run is refused, not trimmed: a partial spend reads as a full picture. */
it('refuses the entire run rather than trimming it over the ceiling', function () {
    Queue::fake();
    $f = runAllFixture(3);
    config(['launchpad.geo_grid.request_ceiling' => 1]);

    $plan = app(CoverageRunAll::class)->plan($f['site'], $f['location']);
    expect($plan['over_ceiling'])->toBeTrue();

    $result = app(CoverageRunAll::class)->run($f['site'], $f['location']);
    expect($result['queued'])->toBe(0);
    Queue::assertNothingPushed();
});

it('shows the cost and request count beside the per-office GBP button', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = runAllFixture(3);

    Livewire::test(ServiceAreasPage::class)
        ->set('siteId', $f['site']->id)
        ->call('openArea', $f['location']->id)
        ->assertOk()
        ->assertSee('Run all GBP reports')
        ->assertSee('3 of 3 keywords')
        ->assertSeeHtml('wire:click="runAllGbp"');
});

it('shows the sitewide website run with its own cost', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $f = runAllFixture(2);

    Livewire::test(TownRankPage::class)
        ->set('siteId', $f['site']->id)
        ->assertOk()
        ->assertSee('Run all website rankings')
        ->assertSeeHtml('wire:click="runAllKeywords"')
        ->assertSee('2 of 2 keywords');
});

/**
 * The sitewide run QUEUES one job per keyword; it must never post inline. TownRankSweep::run() posts
 * every scan in a loop — correct on a console clock, fatal in a Livewire request, where hundreds of
 * DataForSEO batches would hit the FPM timeout having already paid for the half that went out.
 */
it('queues one job per keyword rather than posting inline', function () {
    Queue::fake();
    $f = runAllFixture(3);

    $result = app(TownRankRunAll::class)->run($f['site']);

    expect($result['queued'])->toBe(3);
    Queue::assertPushed(RunTownRankKeyword::class, 3);
});

/** A keyword already collecting is left out of the quote and the run — its requests are already bought. */
it('excludes a collecting keyword from the sitewide quote and run', function () {
    Queue::fake();
    $f = runAllFixture(3);
    TownRankScan::create(['site_id' => $f['site']->id, 'keyword_id' => $f['keywords'][0]->id, 'mode' => 'town_query',
        'status' => 'pending', 'points_count' => 2, 'found_count' => 0, 'scanned_at' => now()]);
    TownRankScan::create(['site_id' => $f['site']->id, 'keyword_id' => $f['keywords'][0]->id, 'mode' => 'local',
        'status' => 'pending', 'points_count' => 2, 'found_count' => 0, 'scanned_at' => now()]);

    $plan = app(TownRankRunAll::class)->plan($f['site']);
    expect($plan['tracked'])->toBe(3)
        ->and($plan['pending'])->toBe(1)
        ->and(count($plan['runnable']))->toBe(2);

    app(TownRankRunAll::class)->run($f['site']);
    Queue::assertPushed(RunTownRankKeyword::class, 2);
});
