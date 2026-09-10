<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Job;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\JobProximityReport;

it('buckets each location page by jobs within the radius of its own subject, and drops the rest', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    // The parent GBP location (a hub measures jobs from its own coordinates).
    $parent = Location::factory()->create(['site_id' => $site->id, 'name' => 'Hoboken office', 'lat' => 40.745, 'lng' => -74.030]);
    // The town subject's centroid comes from CoverageArea (geo_id/name).
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Hoboken', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '3401732250', 'lat' => 40.745, 'lng' => -74.030, 'size_tier' => 'large', 'population' => 60000,
    ]);

    // Two published jobs within ~2 mi of Hoboken, one ~52 mi away (must be excluded).
    $job = fn (float $lat, float $lng) => Job::factory()->published()->create([
        'site_id' => $site->id, 'lat_jittered' => $lat, 'lng_jittered' => $lng,
    ]);
    $job(40.73, -74.06);   // in range
    $job(40.77, -74.02);   // in range
    $job(40.22, -74.76);   // ~52 mi — out of range

    // A HUB page (measures from the parent location), an anchored TOWN page, and an un-anchored town.
    Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => $parent->id, 'title' => 'Hoboken', 'slug' => 'hoboken-market',
    ]);
    Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => '3401732250',
        'title' => 'Hoboken, NJ', 'slug' => 'hoboken-nj',
    ]);
    Content::factory()->published()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'location_id' => null, 'parent_location_id' => $parent->id, 'geo_id' => null,
        'title' => 'Nowheresville, NJ', 'slug' => 'nowheresville-nj',
    ]);

    $entry = app(JobProximityReport::class)->forSite($site->fresh());

    expect($entry['jobs'])->toBe(3)
        ->and($entry['total'])->toBe(3)          // hub + 2 towns
        ->and($entry['hub'])->toBe(1)
        ->and($entry['town'])->toBe(2)
        ->and($entry['dist']['n1_2'])->toBe(2)   // hub + anchored town: 2 in range each (the ~52 mi job excluded)
        ->and($entry['dist']['n3'])->toBe(0)
        ->and($entry['dist']['drop'])->toBe(1)   // the un-anchored town
        ->and($entry['no_subject'])->toBe(1);

    $this->artisan('launchpad:report-job-proximity')
        ->assertSuccessful()
        ->expectsOutputToContain('READ-ONLY');
});
