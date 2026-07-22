<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Locations\TownLocationAssigner;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;

function taSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG']);
}

function taTownPage(Site $site, string $title, string $slug): Content
{
    return Content::factory()->create([
        'site_id' => $site->id,
        'kind' => ContentKind::Page,
        'page_type' => PageType::Location,
        'status' => ContentStatus::Candidate,
        'title' => $title,
        'slug' => $slug,
        'location_id' => null,
        'parent_location_id' => null,
    ]);
}

it('assigns each town page to its GBP location from the intake coverage areas — even with no served_towns', function () {
    $site = taSite();
    // Two GBP locations imported at intake, NEITHER with a hand-curated served_towns list.
    $edison = Location::factory()->create(['site_id' => $site->id, 'name' => 'Edison', 'served_towns' => []]);
    $bristol = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bristol', 'served_towns' => []]);

    // The computed coverage that decided these towns were page-worthy — each carries its owning shop.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Woodbridge', 'state' => 'NJ', 'source_location_ids' => [$edison->id], 'page_selected' => true]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Levittown', 'state' => 'PA', 'source_location_ids' => [$bristol->id], 'page_selected' => true]);

    $woodbridge = taTownPage($site, 'Woodbridge, NJ', 'woodbridge-nj');
    $levittown = taTownPage($site, 'Levittown, PA', 'levittown-pa');

    $result = app(TownLocationAssigner::class)->assign($site);

    expect($result['assigned'])->toBe(2)
        ->and($woodbridge->fresh()->parent_location_id)->toBe($edison->id)
        ->and($levittown->fresh()->parent_location_id)->toBe($bristol->id);
});

it('coverage wins over a stale served_towns guess', function () {
    $site = taSite();
    $edison = Location::factory()->create(['site_id' => $site->id, 'name' => 'Edison', 'served_towns' => []]);
    // Bristol still lists Woodbridge in its hand-curated list, but the computed coverage says Edison.
    $bristol = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bristol', 'served_towns' => [
        ['name' => 'Woodbridge', 'state' => 'NJ', 'lat' => null, 'lng' => null, 'geocoded' => false],
    ]]);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Woodbridge', 'state' => 'NJ', 'source_location_ids' => [$edison->id], 'page_selected' => true]);

    $woodbridge = taTownPage($site, 'Woodbridge, NJ', 'woodbridge-nj');

    app(TownLocationAssigner::class)->assign($site);

    expect($woodbridge->fresh()->parent_location_id)->toBe($edison->id);
});

it('falls back to served_towns for a town with no computed coverage', function () {
    $site = taSite();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Edison', 'served_towns' => []]);
    $bristol = Location::factory()->create(['site_id' => $site->id, 'name' => 'Bristol', 'served_towns' => [
        ['name' => 'Croydon', 'state' => 'PA', 'lat' => null, 'lng' => null, 'geocoded' => false],
    ]]);

    // No CoverageArea for Croydon — the served_towns fallback owns it.
    $croydon = taTownPage($site, 'Croydon, PA', 'croydon-pa');

    $result = app(TownLocationAssigner::class)->assign($site);

    expect($result['assigned'])->toBe(1)
        ->and($croydon->fresh()->parent_location_id)->toBe($bristol->id);
});

it('leaves a town with no owning coverage or served_towns unassigned', function () {
    $site = taSite();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Edison', 'served_towns' => []]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Bristol', 'served_towns' => []]);

    $orphan = taTownPage($site, 'Far Town, NY', 'far-town-ny');

    $result = app(TownLocationAssigner::class)->assign($site);

    expect($result['assigned'])->toBe(0)
        ->and($result['unmatched'])->toBe(['Far Town, NY'])
        ->and($orphan->fresh()->parent_location_id)->toBeNull();
});
