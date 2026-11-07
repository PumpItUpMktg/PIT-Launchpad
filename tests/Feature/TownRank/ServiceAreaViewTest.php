<?php

use App\Jobs\BuildServiceArea;
use App\Jobs\CollectTownRankScan;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\ServiceAreaView;
use App\TownRank\TownRankScanner;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

function areaViewSite(): array
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown office', 'lat' => 40.85, 'lng' => -74.83]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id], 'geo_id' => '3404128590']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);

    return [$site, $loc, $kw];
}

it('serves nothing until the worker has built the area, asks for one build, then serves the built copy', function () {
    Bus::fake();
    [$site, $loc] = areaViewSite();

    expect(ServiceAreaView::ensureFresh($site, (string) $loc->id))->toBeNull();
    Bus::assertDispatched(BuildServiceArea::class, fn (BuildServiceArea $j) => $j->siteId === (string) $site->id && $j->locationId === (string) $loc->id);
    expect((new BuildServiceArea((string) $site->id, (string) $loc->id))->uniqueId())->toBe($site->id.':'.$loc->id);

    (new BuildServiceArea((string) $site->id, (string) $loc->id))->handle();
    $view = ServiceAreaView::get($site, (string) $loc->id);
    expect($view)->not->toBeNull()
        ->and($view['area']['location']['name'])->toBe('Hackettstown office')
        ->and(ServiceAreaView::ensureFresh($site, (string) $loc->id))->not->toBeNull();
    Bus::assertDispatchedTimes(BuildServiceArea::class, 1);   // fresh: no second ask
});

it('a completed scan forgets the site\'s areas so the next open rebuilds; an unknown location builds nothing', function () {
    [$site, $loc, $kw] = areaViewSite();
    ServiceAreaView::build($site, (string) $loc->id);
    expect(ServiceAreaView::get($site, (string) $loc->id))->not->toBeNull();

    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'pending', 'points_count' => 0, 'scanned_at' => now()]);
    (new CollectTownRankScan($scan->id))->handle(app(TownRankScanner::class));   // no points → completes at once

    expect($scan->fresh()->status)->toBe('complete')
        ->and(ServiceAreaView::get($site, (string) $loc->id))->toBeNull()
        ->and(ServiceAreaView::build($site, 'not-a-location'))->toBeNull();
});
