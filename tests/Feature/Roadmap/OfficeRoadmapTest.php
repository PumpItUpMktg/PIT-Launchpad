<?php

use App\Models\GbpProfile;
use App\Models\Scopes\SiteScope;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Roadmap\OfficeRoadmap;

require_once __DIR__.'/RoadmapFixtures.php';

function roadmapProfiled(array $world): void
{
    foreach ([
        ['PID_US', $world['office']->id, 'Sump Pump Gurus | Hackettstown', 4.7, 42, 'plumber', 6, 40.8540, -74.8290],
        ['PID_ORIOLE', null, 'Oriole Basement Waterproofing', 4.9, 180, 'well_drilling_contractor', 40, 40.9300, -74.9000],
        ['PID_DL', null, 'D&L Pump and Well', 4.8, 95, 'well_drilling_contractor', 22, 40.9700, -74.9700],
        ['PID_TSC', null, 'Tractor Supply', 4.4, 400, 'hardware_store', 60, 40.7600, -74.9800],
    ] as [$pid, $loc, $name, $rating, $ratings, $type, $photos, $lat, $lng]) {
        GbpProfile::withoutGlobalScope(SiteScope::class)->create(['site_id' => $world['site']->id, 'place_id' => $pid, 'location_id' => $loc, 'name' => $name, 'rating' => $rating, 'ratings_total' => $ratings,
            'types' => [$type], 'primary_type' => $type, 'photo_count' => $photos, 'business_status' => 'OPERATIONAL', 'lat' => $lat, 'lng' => $lng, 'refreshed_at' => now()]);
    }
}

it('reads the pack per keyword, compares the listing to the winners, and turns the gaps into ordered actions', function () {
    $w = roadmapWorld();
    roadmapProfiled($w);
    // Organic town results for the office's towns: page 1 in Hackettstown, off page 1 in the other two.
    $tr = TownRankScan::create(['site_id' => $w['site']->id, 'keyword_id' => $w['keyword']->id, 'mode' => 'town_query', 'status' => 'complete', 'points_count' => 3, 'found_count' => 2, 'scanned_at' => '2026-10-19 06:00:00']);
    foreach ([['Hackettstown', 4], ['Washington', 14], ['Blairstown', null]] as $i => [$name, $rank]) {
        TownRankPoint::create(['site_id' => $w['site']->id, 'scan_id' => $tr->id, 'coverage_area_id' => $w['towns'][$name]->id, 'geo_id' => $w['towns'][$name]->geo_id, 'label' => $name, 'lat' => 40.8 + $i, 'lng' => -74.9, 'query' => 'q', 'rank' => $rank, 'collected_at' => '2026-10-19 06:10:00']);
    }

    $r = app(OfficeRoadmap::class)->for($w['office']);

    expect($r['towns'])->toBe(3)->and($r['keywords'])->toHaveCount(1);
    $k = $r['keywords'][0];
    expect($k['query'])->toBe('sump pump installation')
        ->and($k['scanned'])->toBe(3)->and($k['in_pack'])->toBe(1)->and($k['in_top7'])->toBe(2)->and($k['absent'])->toBe(1)
        ->and(array_column($k['winners'], 'name'))->toBe(['Oriole Basement Waterproofing', 'D&L Pump and Well', 'Tractor Supply'])
        ->and($k['winners'][0]['cells'])->toBe(3)->and($k['winners'][0]['domain'])->toBe('oriolebw.com')
        ->and($k['organic'])->toBe(['scanned' => 3, 'page1' => 1, 'off_page1' => 2])
        ->and($k['type_match'])->toBe(['ours' => 'plumber', 'winners_type' => 'well_drilling_contractor', 'share' => 0.0]);

    $rows = collect($r['listing']['rows'])->keyBy('key');
    expect($rows['rating']['ours'])->toBe(4.7)->and($rows['rating']['median'])->toBe(4.8)->and($rows['rating']['gap'])->toBeTrue()
        ->and($rows['ratings_total']['ours'])->toBe(42)->and($rows['ratings_total']['median'])->toBe(180)->and($rows['ratings_total']['best'])->toBe(400)
        ->and($rows['photo_count']['median'])->toBe(40)->and($r['listing']['winners_profiled'])->toBe(3);

    // Blairstown: Oriole and D&L both sit closer than the office, and the office is > 1.5× D&L's distance.
    expect(array_column($r['proximity']['out_of_reach'], 'label'))->toBe(['Blairstown'])
        ->and($r['proximity']['out_of_reach'][0]['winner'])->toBe('D&L Pump and Well');

    expect(array_column($r['actions'], 'key'))->toBe(['category:'.$w['keyword']->id, 'reviews', 'rating', 'photos', 'reach'])
        ->and($r['actions'][0]['detail'])->toContain('Well drilling contractor')
        ->and($r['actions'][1]['detail'])->toContain('42 here against a winners\' median of 180 (best 400)');
});

it('asks for the profiles first when nothing is profiled, and judges nothing it has no data for', function () {
    $w = roadmapWorld();

    $r = app(OfficeRoadmap::class)->for($w['office']);

    expect($r['office']['profile'])->toBeNull()
        ->and($r['keywords'][0]['winners'])->toHaveCount(3)
        ->and($r['listing']['winners_profiled'])->toBe(0)->and($r['listing']['winners_total'])->toBe(3)
        ->and($r['proximity']['unknown'])->toBe(3)
        ->and(array_column($r['actions'], 'key'))->toBe(['profiles']);
});
