<?php

use App\Models\GbpProfile;
use App\Models\Scopes\SiteScope;
use App\Roadmap\CompetitorProfiler;

require_once __DIR__.'/RoadmapFixtures.php';

it('profiles the office and its top map-pack competitors, with the backlink summary for a competitor with a domain', function () {
    ['site' => $site, 'office' => $office] = roadmapWorld();
    $places = scriptedPlaces([
        'PID_US' => roadmapProfile('PID_US', 'Sump Pump Gurus | Hackettstown', 4.7, 42, 'plumber', 6, 40.8540, -74.8290, 'https://sumppumpgurus.com/hackettstown-nj/'),
        'PID_ORIOLE' => roadmapProfile('PID_ORIOLE', 'Oriole Basement Waterproofing', 4.9, 180, 'waterproofing_company', 40, 40.86, -74.80, 'https://www.oriolebw.com'),
        'PID_DL' => roadmapProfile('PID_DL', 'D&L Pump and Well', 4.8, 95, 'well_drilling_contractor', 22, 40.90, -74.90),
        // PID_TSC is not scripted: Places cannot resolve it.
    ]);
    $backlinks = summaryBacklinks(['oriolebw.com' => [140, 31], 'dlpumpandwell.com' => [12, 8]]);

    $r = (new CompetitorProfiler($places, $backlinks))->refresh($site);

    expect($r)->toBe(['offices' => 1, 'competitors' => 3, 'profiled' => 3, 'unresolved' => 1])
        ->and($places->asked)->toBe(['PID_US', 'PID_ORIOLE', 'PID_DL', 'PID_TSC']); // the office first, then winners by cells

    $rows = GbpProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('place_id');
    expect($rows)->toHaveCount(3)
        ->and($rows['PID_US']->location_id)->toBe((string) $office->id)
        ->and($rows['PID_US']->referring_domains)->toBeNull()   // our own site: no summary call
        ->and($rows['PID_US']->primary_type)->toBe('plumber')
        ->and($rows['PID_ORIOLE']->location_id)->toBeNull()
        ->and($rows['PID_ORIOLE']->domain)->toBe('oriolebw.com')
        ->and($rows['PID_ORIOLE']->referring_domains)->toBe(140)->and($rows['PID_ORIOLE']->authority)->toBe(31)
        ->and($rows['PID_ORIOLE']->ratings_total)->toBe(180)->and($rows['PID_ORIOLE']->photo_count)->toBe(40)
        ->and($rows['PID_DL']->domain)->toBe('dlpumpandwell.com')->and($rows['PID_DL']->referring_domains)->toBe(12);
});

it('caps the competitors per office and keeps a row Places can no longer resolve', function () {
    config(['launchpad.roadmap.competitors_per_office' => 1]);
    ['site' => $site] = roadmapWorld();
    $places = scriptedPlaces(['PID_US' => roadmapProfile('PID_US', 'Us', 4.7, 42, 'plumber', 6, 40.85, -74.83), 'PID_ORIOLE' => roadmapProfile('PID_ORIOLE', 'Oriole', 4.9, 180, 'plumber', 40, 40.86, -74.80)]);
    (new CompetitorProfiler($places, summaryBacklinks([])))->refresh($site);
    expect(GbpProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('place_id')->sort()->values()->all())->toBe(['PID_ORIOLE', 'PID_US']);

    $r = (new CompetitorProfiler(scriptedPlaces(['PID_US' => roadmapProfile('PID_US', 'Us', 4.7, 50, 'plumber', 6, 40.85, -74.83)]), summaryBacklinks([])))->refresh($site);
    expect($r['unresolved'])->toBe(1)
        ->and(GbpProfile::withoutGlobalScope(SiteScope::class)->where('place_id', 'PID_ORIOLE')->exists())->toBeTrue()   // kept
        ->and(GbpProfile::withoutGlobalScope(SiteScope::class)->where('place_id', 'PID_US')->value('ratings_total'))->toBe(50); // refreshed
});
