<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Operator\Coverage\LocationDuplicateReconciler;
use Illuminate\Support\Facades\Artisan;

function reconLocPage(Site $s, string $title, string $slug, ?string $locationId, ?string $parentId): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'title' => $title, 'slug' => $slug,
        'location_id' => $locationId, 'parent_location_id' => $parentId,
    ]);
}

function reconCityLocation(Site $s, string $city, string $state): Location
{
    return Location::factory()->create(['site_id' => $s->id, 'address_components' => [
        ['types' => ['locality'], 'long_name' => $city],
        ['types' => ['administrative_area_level_1'], 'short_name' => $state],
    ]]);
}

it('surfaces a live landing↔town duplicate (the output smell)', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    reconLocPage($site, 'Hoboken, NJ', 'hoboken-nj', $loc->id, null);
    reconLocPage($site, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', null, $loc->id);

    $report = app(LocationDuplicateReconciler::class)->report($site);

    expect($report['live_duplicates'])->toHaveCount(1)
        ->and($report['live_duplicates'][0]['ambiguous'])->toBeFalse()
        ->and($report['live_duplicates'][0]['keeper']['role'])->toBe('landing')
        ->and($report['selected_self_cities'])->toBe([]);
});

it('surfaces a page_selected town that is a physical location\'s own city (the input smell)', function () {
    $site = Site::factory()->create();
    reconCityLocation($site, 'Downingtown', 'PA');
    $selfCity = CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'D1', 'name' => 'Downingtown', 'state' => 'PA', 'page_selected' => true, 'source' => 'county']);
    // A normal selected town that is NOT a location city — must not be flagged.
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'D2', 'name' => 'Exton', 'state' => 'PA', 'page_selected' => true, 'source' => 'county']);

    $report = app(LocationDuplicateReconciler::class)->report($site);

    expect($report['selected_self_cities'])->toHaveCount(1)
        ->and($report['selected_self_cities'][0]['coverage_area_id'])->toBe($selfCity->id)
        ->and($report['selected_self_cities'][0]['name'])->toBe('Downingtown');
});

it('reports a clean tenant as nothing on both ends', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id]);
    reconLocPage($site, 'Newark, NJ', 'newark-nj', $loc->id, null); // sole landing, no twin
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => 'N9', 'name' => 'Kearny', 'state' => 'NJ', 'page_selected' => true, 'source' => 'county']);

    $report = app(LocationDuplicateReconciler::class)->report($site);

    expect($report['live_duplicates'])->toBe([])
        ->and($report['selected_self_cities'])->toBe([]);
});

it('command prints both smells and a clean run', function () {
    // Dirty tenant.
    $dirty = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $loc = reconCityLocation($dirty, 'Hoboken', 'NJ');
    reconLocPage($dirty, 'Hoboken, NJ', 'hoboken-nj', $loc->id, null);
    reconLocPage($dirty, 'Hoboken, NJ', 'hoboken-nj/hoboken-nj', null, $loc->id);
    CoverageArea::factory()->create(['site_id' => $dirty->id, 'geo_id' => 'H1', 'name' => 'Hoboken', 'state' => 'NJ', 'page_selected' => true, 'source' => 'county']);

    $code = Artisan::call('launchpad:reconcile-location-duplicates', ['--site' => $dirty->id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('LIVE DUPLICATE')
        ->and($out)->toContain('SELF-CITY SELECTED')
        ->and($out)->toContain('run launchpad:resolve-live-duplicates');

    // Clean tenant.
    $clean = Site::factory()->create(['brand_name' => 'CleanCo', 'domain_url' => 'https://clean.example']);
    Location::factory()->create(['site_id' => $clean->id]);

    $code = Artisan::call('launchpad:reconcile-location-duplicates', ['--site' => $clean->id]);
    expect($code)->toBe(0)->and(Artisan::output())->toContain('Clean — no live duplicate');
});
