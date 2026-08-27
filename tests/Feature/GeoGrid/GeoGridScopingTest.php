<?php

use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

it('opts a keyword into grid scanning via is_grid_keyword (default false)', function () {
    $site = Site::factory()->create();
    $plain = Keyword::factory()->create(['site_id' => $site->id]);
    $grid = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);

    expect($plain->refresh()->is_grid_keyword)->toBeFalse()   // DB default applies
        ->and($grid->refresh()->is_grid_keyword)->toBeTrue();
});

it('scopes to GBP-backed locations only, excluding no-listing and merged rows', function () {
    $site = Site::factory()->create();
    $backed = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://maps.google/?cid=1', 'place_id' => 'ChIJ_a', 'lat' => 40.7, 'lng' => -74.2]);
    Location::factory()->create(['site_id' => $site->id, 'gbp_url' => null]);                                   // home base — no listing
    Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://maps.google/?cid=2', 'merged_into_id' => $backed->id]); // folded away

    $ids = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->gbpBacked()->pluck('id');

    expect($ids->all())->toBe([$backed->id])
        ->and($backed->isGbpBacked())->toBeTrue();
});

it('is grid-ready only with a place_id and a center coordinate', function () {
    $site = Site::factory()->create();
    $ready = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=1', 'place_id' => 'ChIJ_x', 'lat' => 40.7, 'lng' => -74.2]);
    $noPlace = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=2', 'place_id' => null, 'lat' => 40.7, 'lng' => -74.2]);
    $noCoord = Location::factory()->create(['site_id' => $site->id, 'gbp_url' => 'https://g/?cid=3', 'place_id' => 'ChIJ_y', 'lat' => null, 'lng' => null]);

    expect($ready->isGridReady())->toBeTrue()
        ->and($noPlace->isGridReady())->toBeFalse()
        ->and($noCoord->isGridReady())->toBeFalse();

    $ids = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->gridReady()->pluck('id');
    expect($ids->all())->toBe([$ready->id]);
});

it('falls back to the default grid spacing when the location has no override', function () {
    $site = Site::factory()->create();
    $default = Location::factory()->create(['site_id' => $site->id, 'grid_spacing_miles' => null]);
    $custom = Location::factory()->create(['site_id' => $site->id, 'grid_spacing_miles' => 3.0]);

    expect($default->gridSpacingMiles())->toBe(1.5)
        ->and($custom->gridSpacingMiles())->toBe(3.0);
});
