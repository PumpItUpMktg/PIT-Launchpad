<?php

use App\Models\CoverageArea;
use App\Models\Site;

test('the drip command seeds the population selection for a single site', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '01', 'size_tier' => 'major', 'population' => 60000, 'page_selected' => false, 'source' => 'county']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '02', 'size_tier' => 'small', 'population' => 4000, 'page_selected' => false, 'source' => 'county']);

    $this->artisan('launchpad:drip-towns', ['--site' => $site->id])->assertSuccessful();

    expect(CoverageArea::where('site_id', $site->id)->where('geo_id', '01')->value('page_selected'))->toBe(true)
        ->and(CoverageArea::where('site_id', $site->id)->where('geo_id', '02')->value('page_selected'))->toBe(false);
});

test('the drip command fails clearly when the site id is unknown', function () {
    $this->artisan('launchpad:drip-towns', ['--site' => 'nope'])->assertFailed();
});
