<?php

use App\KeywordGenerator\Beatability\CompetitorClassifier;
use App\Links\CompetitorTargets;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use Illuminate\Support\Carbon;

function ctScan(Site $site, Keyword $kw, string $mode, Carbon $at, array $points): void
{
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => $mode, 'status' => 'complete', 'points_count' => count($points), 'found_count' => 0, 'scanned_at' => $at]);
    foreach ($points as $i => [$rank, $top]) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'label' => "Town {$i}", 'lat' => 40.0 + $i, 'lng' => -74.0, 'query' => $kw->query, 'rank' => $rank, 'collected_at' => $at,
            'top_results' => array_map(fn (array $r): array => ['position' => $r[0], 'url' => "https://{$r[1]}/p", 'domain' => $r[1]], $top)]);
    }
}

it('ranks the local competitors found above us across priority keywords, dropping aggregators, chains, us, and anyone below us', function () {
    $site = Site::factory()->create(['domain_url' => 'https://www.sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'town_priority_rank' => 1]);
    $old = Carbon::parse('2026-09-01');
    $new = Carbon::parse('2026-10-01');
    // An older scan whose competitor must NOT count (only the latest scan per keyword/mode is read).
    ctScan($site, $kw, 'town_query', $old, [[1, [[1, 'stale-rival.com']]]]);
    ctScan($site, $kw, 'town_query', $new, [
        [3, [[1, 'www.rivalplumbing.com'], [2, 'yelp.com'], [3, 'sumppumpgurus.com'], [4, 'belowus.com']]],
        [null, [[1, 'rivalplumbing.com'], [2, 'dryguys.com'], [3, 'homedepot.com']]],
    ]);
    ctScan($site, $kw, 'local', $new, [[2, [[1, 'dryguys.com'], [2, 'sumppumpgurus.com']]]]);

    $targets = app(CompetitorTargets::class)->for($site, 10);

    expect($targets)->toBe([
        ['domain' => 'dryguys.com', 'appearances' => 2, 'source' => 'organic'],     // ties break by name, so the order is stable
        ['domain' => 'rivalplumbing.com', 'appearances' => 2, 'source' => 'organic'],
    ]);
});

it('falls back to every scanned keyword when none is a priority, and caps the list', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'radon mitigation', 'town_priority_rank' => null]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'a.com'], [2, 'b.com'], [3, 'c.com']]]]);

    expect(array_column(app(CompetitorTargets::class)->for($site, 2), 'domain'))->toBe(['a.com', 'b.com']);
});

it('never treats a platform host as a competitor — a Facebook page or a Google Business site above us is not a target', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'town_priority_rank' => 1]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'www.facebook.com'], [2, 'rival-pumps.business.site'], [3, 'localrival.com'], [4, 'smith-plumbing.wixsite.com']]]]);

    expect(array_column(app(CompetitorTargets::class)->for($site, 10), 'domain'))->toBe(['localrival.com']);
});

it('reads the registrable domain, drops foreign country-code sites, forums and franchises — what Sump Pump Gurus\' first run let through', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump installation', 'town_priority_rank' => 1]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [
        [1, 'doityourself.com'], [2, 'm.yelp.com'], [3, 'harrisplumbing.ca'], [4, 'ca.jackery.com'], [5, 'mrhandyman.com'],
        [6, 'www.oriolebw.com'], [7, 'blog.oriolebw.com'], [8, 'patriotpumpservice.com'],
    ]]]);

    expect(app(CompetitorTargets::class)->for($site, 10))->toBe([
        ['domain' => 'oriolebw.com', 'appearances' => 1, 'source' => 'organic'],   // www. and blog. are one business, counted once per town
        ['domain' => 'patriotpumpservice.com', 'appearances' => 1, 'source' => 'organic'],
    ]);
});

it('the registrable domain keeps a two-level public suffix', function () {
    expect(CompetitorClassifier::registrable('m.yelp.com'))->toBe('yelp.com')
        ->and(CompetitorClassifier::registrable('shop.example.co.uk'))->toBe('example.co.uk')
        ->and(CompetitorClassifier::registrable('rival-pumps.business.site'))->toBe('business.site')
        ->and(CompetitorClassifier::registrable('rivalplumbing.us.com'))->toBe('rivalplumbing.us.com'); // a reseller domain, not "us.com"
});

it('drops a domain whose footprint is the whole territory — no local company beats us in most town results', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency sump pump', 'town_priority_rank' => 1]);
    // 24 towns: the Florida site is above us in every one; the local rival in three.
    $towns = [];
    for ($i = 0; $i < 24; $i++) {
        $towns[] = [null, $i < 3 ? [[1, 'barbaraemergencyrestorationpompanobeachfl.com'], [2, 'oriolebw.com']] : [[1, 'barbaraemergencyrestorationpompanobeachfl.com']]];
    }
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), $towns);

    expect(array_column(app(CompetitorTargets::class)->for($site, 10), 'domain'))->toBe(['oriolebw.com']);

    // Below the sample size nothing is judged on footprint.
    $small = Site::factory()->create(['domain_url' => 'https://other.example']);
    $kw2 = Keyword::factory()->create(['site_id' => $small->id, 'query' => 'q', 'town_priority_rank' => 1]);
    ctScan($small, $kw2, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'everywhere.com']]], [null, [[1, 'everywhere.com']]]]);
    expect(array_column(app(CompetitorTargets::class)->for($small, 10), 'domain'))->toBe(['everywhere.com']);
});

it('judges the footprint per scan — a site that owns one keyword everywhere is dropped even when five other keywords dilute it', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $owned = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency restoration', 'town_priority_rank' => 1]);
    $other = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'town_priority_rank' => 2]);
    $fl = fn (): array => [null, [[1, 'barbaraemergencyrestorationpompanobeachfl.com']]];
    $local = fn (): array => [null, [[1, 'oriolebw.com']]];
    ctScan($site, $owned, 'town_query', Carbon::parse('2026-10-01'), array_fill(0, 24, $fl()));         // 24/24 for this keyword
    ctScan($site, $other, 'town_query', Carbon::parse('2026-10-01'), array_merge(array_fill(0, 10, $local()), array_fill(0, 86, [null, []]))); // 96 results the Florida site is absent from; the rival in 10

    $targets = app(CompetitorTargets::class);
    expect(array_column($targets->for($site, 10), 'domain'))->toBe(['oriolebw.com'])
        ->and($targets->dropped)->toBe([['domain' => 'barbaraemergencyrestorationpompanobeachfl.com', 'appearances' => 24, 'share' => 1.0]])
        ->and($targets->for($site, 10)[0]['appearances'])->toBe(10);
});

it('puts the map-pack businesses first — local by definition — and fills the rest from the organic results', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $office = Location::factory()->create(['site_id' => $site->id]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'town_priority_rank' => 1]);
    // An older grid scan (no domains on its rows — before the scanner kept them) and the latest one.
    foreach ([['2026-09-01', [['name' => 'Old Co', 'place_id' => 'p0', 'rank' => 1]]], ['2026-10-01', [['name' => 'Oriole', 'place_id' => 'p1', 'rank' => 1, 'domain' => 'www.oriolebw.com'], ['name' => 'Yelp', 'place_id' => 'p2', 'rank' => 2, 'domain' => 'm.yelp.com'], ['name' => 'Pink Duck', 'place_id' => 'p3', 'rank' => 3, 'domain' => 'pinkduckplumbing.com']]]] as [$at, $competitors]) {
        $scan = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $office->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'grid_size' => 3, 'spacing_miles' => 1, 'center_lat' => 40.7, 'center_lng' => -74.0, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => $at]);
        foreach ([[0, 0], [0, 1]] as [$r, $c]) {
            GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => $r, 'col' => $c, 'lat' => 40.7, 'lng' => -74.0, 'rank' => 4, 'competitors' => $c === 0 ? $competitors : array_slice($competitors, 0, 1)]);
        }
    }
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'patriotpumpservice.com'], [2, 'oriolebw.com']]]]);

    expect(app(CompetitorTargets::class)->for($site, 10))->toBe([
        ['domain' => 'oriolebw.com', 'appearances' => 2, 'source' => 'map_pack'],       // both cells of the latest scan; www. stripped
        ['domain' => 'pinkduckplumbing.com', 'appearances' => 1, 'source' => 'map_pack'],
        ['domain' => 'patriotpumpservice.com', 'appearances' => 1, 'source' => 'organic'], // oriolebw.com not repeated from the organic side
    ]);
});

it('counts a domain once per town however many of its URLs sit in the top five, so a share can never exceed 100%', function () {
    $site = Site::factory()->create(['domain_url' => 'https://sumppumpgurus.com']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'q', 'town_priority_rank' => 1]);
    ctScan($site, $kw, 'town_query', Carbon::parse('2026-10-01'), [[null, [[1, 'oriolebw.com'], [2, 'oriolebw.com'], [3, 'oriolebw.com']]]]);

    expect(app(CompetitorTargets::class)->for($site, 10))->toBe([['domain' => 'oriolebw.com', 'appearances' => 1, 'source' => 'organic']]);
});
