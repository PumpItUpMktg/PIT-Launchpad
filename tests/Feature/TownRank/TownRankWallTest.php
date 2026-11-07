<?php

use App\Jobs\BuildTownRankWall;
use App\Jobs\CollectTownRankScan;
use App\Models\Keyword;
use App\Models\Site;
use App\Models\TownRankScan;
use App\TownRank\TownRankScanner;
use App\TownRank\TownRankWall;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('reads nothing until the worker has built the wall, asks for a build once, and serves the built copy', function () {
    Bus::fake();
    $site = Site::factory()->create();
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);

    expect(TownRankWall::get($site))->toBeNull()
        ->and(TownRankWall::ensureFresh($site))->toBeNull();   // nothing to show yet — the page says "building"
    Bus::assertDispatched(BuildTownRankWall::class, fn (BuildTownRankWall $j) => $j->siteId === (string) $site->id);
    expect((new BuildTownRankWall((string) $site->id))->uniqueId())->toBe((string) $site->id);

    (new BuildTownRankWall((string) $site->id))->handle();
    $wall = TownRankWall::get($site);
    expect($wall)->not->toBeNull()
        ->and($wall['cards'][0]['query'])->toBe('sump pump service')
        ->and($wall['built_at'])->toBeString();
});

it('serves a fresh copy without asking, and a stale copy while asking for a rebuild', function () {
    Bus::fake();
    $site = Site::factory()->create();
    TownRankWall::build($site);

    expect(TownRankWall::ensureFresh($site))->not->toBeNull();
    Bus::assertNotDispatched(BuildTownRankWall::class);

    $this->travel(TownRankWall::FRESH_SECONDS + 5)->seconds();
    $stale = TownRankWall::ensureFresh($site);
    expect($stale)->not->toBeNull();                              // the page shows what it has …
    Bus::assertDispatched(BuildTownRankWall::class);              // … and the worker rebuilds it
});

it('a scan completing asks for a rebuild, so the card flips from collecting to its result', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'pending', 'points_count' => 0, 'scanned_at' => now()]);

    // No uncollected points: the collector finalizes it as complete in one pass; the sync queue builds the wall.
    (new CollectTownRankScan($scan->id))->handle(app(TownRankScanner::class));

    expect($scan->fresh()->status)->toBe('complete')
        ->and(TownRankWall::get($site))->not->toBeNull()
        ->and(TownRankWall::get($site)['cards'][0]['pending'])->toBeFalse();
});
