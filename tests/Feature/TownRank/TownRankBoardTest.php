<?php

use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownDiagnosis;
use App\TownRank\TownRankBoard;

/** A site with three towns: Hackettstown (anchored page), Mansfield (page published but NOT anchored), Independence (no page). */
function boardSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $hack = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Hackettstown', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id], 'geo_id' => '3404128590']);
    $mans = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mansfield', 'state' => 'NJ', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => [$loc->id], 'geo_id' => '3404143050']);
    $indy = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Independence', 'state' => 'NJ', 'population' => 5000, 'lat' => 40.90, 'lng' => -74.80, 'source_location_ids' => [$loc->id], 'geo_id' => '3404134110']);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => '3404128590', 'slug' => 'hackettstown-nj']);
    Content::factory()->page()->published()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'status' => ContentStatus::Published, 'geo_id' => null, 'slug' => 'mansfield-nj']);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service']);

    $tq = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 3, 'found_count' => 2, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $tq->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'sump pump service Hackettstown NJ', 'rank' => 4, 'ranking_url' => 'https://spg.com/hackettstown-nj/', 'collected_at' => now(),
        'top_results' => [['position' => 1, 'url' => 'https://rival.com/h', 'domain' => 'rival.com'], ['position' => 2, 'url' => 'https://yelp.com/x', 'domain' => 'www.yelp.com'], ['position' => 3, 'url' => 'https://other.com', 'domain' => 'other.com'], ['position' => 4, 'url' => 'https://spg.com/hackettstown-nj/', 'domain' => 'spg.com'], ['position' => 5, 'url' => 'https://below.com', 'domain' => 'below.com']]]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $tq->id, 'coverage_area_id' => $mans->id, 'label' => 'Mansfield', 'state' => 'NJ', 'lat' => 40.80, 'lng' => -74.85, 'query' => 'sump pump service Mansfield NJ', 'rank' => 14, 'ranking_url' => 'https://spg.com/mansfield-nj/', 'collected_at' => now(), 'top_results' => []]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $tq->id, 'coverage_area_id' => $indy->id, 'label' => 'Independence', 'state' => 'NJ', 'lat' => 40.90, 'lng' => -74.80, 'query' => 'sump pump service Independence NJ', 'rank' => null, 'collected_at' => now(), 'top_results' => []]);

    $local = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'local', 'status' => 'complete', 'points_count' => 3, 'found_count' => 1, 'scanned_at' => now()]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $local->id, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown', 'state' => 'NJ', 'lat' => 40.85, 'lng' => -74.83, 'query' => 'sump pump service', 'rank' => 8, 'ranking_url' => 'https://spg.com/hackettstown-nj/', 'collected_at' => now(), 'top_results' => []]);

    $gg = GeoGridScan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 1, 'spacing_miles' => 0, 'center_lat' => 40.85, 'center_lng' => -74.83, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now()]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $gg->id, 'row' => 0, 'col' => 0, 'lat' => 40.85, 'lng' => -74.83, 'rank' => 2, 'coverage_area_id' => $hack->id, 'label' => 'Hackettstown']);

    return [$site, $kw, $hack, $mans, $indy];
}

it('lists scanned keywords and builds the board with north-up markers coloured by the selected mode', function () {
    [$site, $kw, $hack, $mans, $indy] = boardSite();
    $board = app(TownRankBoard::class);

    expect($board->keywords($site))->toHaveCount(1)
        ->and($board->keywords($site)[0]['query'])->toBe('sump pump service');

    $tq = $board->for($site, null, 'town_query');   // null → the most recently scanned keyword
    expect($tq['keyword'])->toBe('sump pump service')
        ->and($tq['summary'])->toMatchArray(['page1' => 1, 'page2' => 1, 'not_found' => 1, 'up' => 0, 'down' => 0])
        ->and($tq['has_previous'])->toBeFalse()
        ->and($tq['markers'])->toHaveCount(3)
        ->and($tq['markers'][0]['change'])->toBeNull();
    $byId = collect($tq['markers'])->keyBy('id');
    expect($byId[$hack->id]['rank'])->toBe(4)->and($byId[$hack->id]['page'])->toBeTrue()
        ->and($byId[$mans->id]['page'])->toBeTrue()   // found by slug — a page, just not anchored to this GEOID
        ->and($byId[$indy->id]['rank'])->toBeNull()->and($byId[$indy->id]['page'])->toBeFalse()
        ->and($byId[$indy->id]['y'])->toBeLessThan($byId[$mans->id]['y'])   // north-up: Independence (40.90) above Mansfield (40.80)
        ->and($byId[$mans->id]['x'])->toBeLessThan($byId[$indy->id]['x']);  // west of it

    $local = $board->for($site, $kw->id, 'local');
    expect($local['summary'])->toMatchArray(['page1' => 1, 'not_found' => 2])
        ->and(collect($local['markers'])->keyBy('id')[$hack->id]['rank'])->toBe(8);

    // The card wall: one card per scanned keyword, both modes summarised, thumbnail coloured by the town search;
    // a keyword tracked for Town Rank but never scanned gets a card too (after the scanned ones, no modes).
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'track_town_rank' => true]);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'untracked', 'track_town_rank' => false, 'is_grid_keyword' => false]);
    $cards = $board->cards($site);
    expect($cards)->toHaveCount(2)
        ->and($cards[1]['query'])->toBe('sump pump repair')
        ->and($cards[1]['scanned_at'])->toBeNull()
        ->and($cards[1]['pending'])->toBeFalse()
        ->and($cards[1]['modes'])->toBe(['local' => null, 'town_query' => null])
        ->and($cards[1]['towns'])->toBe(3)
        ->and($cards[0]['query'])->toBe('sump pump service')
        ->and($cards[0]['thumbnail_mode'])->toBe('town_query')
        ->and($cards[0]['modes']['town_query'])->toMatchArray(['page1' => 1, 'page2' => 1, 'not_found' => 1])
        ->and($cards[0]['modes']['local'])->toMatchArray(['page1' => 1, 'not_found' => 2])
        ->and($cards[0]['has_previous'])->toBeFalse()
        ->and(collect($cards[0]['markers'])->keyBy('id')[$hack->id]['rank'])->toBe(4);
});

it('builds a town detail: competitors above us only, page state incl. un-anchored, map pack, and ordered actions', function () {
    [$site, $kw, $hack, $mans, $indy] = boardSite();
    $board = app(TownRankBoard::class);

    $h = $board->town($site, $kw->id, $hack->id);
    expect($h['page_state'])->toBe('anchored')
        ->and($h['town_query']['rank'])->toBe(4)
        ->and(array_column($h['town_query']['competitors'], 'domain'))->toBe(['rival.com', 'yelp.com', 'other.com'])   // #5 below us excluded; www stripped
        ->and($h['local']['rank'])->toBe(8)
        ->and($h['map_rank'])->toBe(2)
        ->and(array_column($h['actions'], 'key'))->toBe(['close_gap', 'local_ok', 'map_ok'])
        ->and($h['actions'][0]['why'])->toContain('rival.com, yelp.com, other.com');

    $m = $board->town($site, $kw->id, $mans->id);
    expect($m['page_state'])->toBe('slug')
        ->and($m['page_url'])->toBe('https://spg.com/mansfield-nj')
        ->and(array_column($m['actions'], 'key'))->toBe(['check_anchor', 'strengthen_page', 'local_weak', 'map_absent']);

    $i = $board->town($site, $kw->id, $indy->id);
    expect($i['page_state'])->toBe('none')
        ->and(array_column($i['actions'], 'key'))->toBe(['build_page', 'local_weak', 'map_absent'])
        ->and($i['actions'][0]['why'])->toContain('Nothing of yours ranks');

    expect($board->town($site, $kw->id, 'nope'))->toBeNull();
});

it('diagnoses the remaining rules: not ranking for its own town, holding top 3, and un-scanned modes', function () {
    $base = ['page_state' => 'anchored', 'map_rank' => null, 'map_scanned' => false];
    $mode = fn (?int $rank, string $state): array => ['rank' => $rank, 'state' => $state, 'competitors' => []];

    $notRanking = TownDiagnosis::for([...$base, 'town_query' => $mode(null, 'not_found'), 'local' => $mode(null, 'unscanned')]);
    expect(array_column($notRanking, 'key'))->toBe(['not_ranking_own_town', 'scan_local', 'no_map_scan']);

    $holding = TownDiagnosis::for([...$base, 'town_query' => $mode(2, 'top3'), 'local' => $mode(3, 'top3'), 'map_rank' => 5, 'map_scanned' => true]);
    expect(array_column($holding, 'key'))->toBe(['hold', 'local_ok', 'map_weak'])
        ->and($holding[0]['level'])->toBe('ok');

    $unscanned = TownDiagnosis::for([...$base, 'town_query' => $mode(null, 'unscanned'), 'local' => $mode(null, 'pending')]);
    expect(array_column($unscanned, 'key'))->toBe(['scan_town_query', 'scan_local', 'no_map_scan']);
});
