<?php

use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

it('previews without changing anything, then applies', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG', 'corporate_state' => 'NJ']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '2402500010', 'name' => 'Bel Air', 'state' => 'MD']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3402700010', 'name' => 'Morristown', 'state' => 'NJ']);

    // Preview leaves the MD row in place.
    $this->artisan('launchpad:repair-coverage', ['site' => 'SPG'])
        ->expectsOutputToContain('Preview only')
        ->assertSuccessful();
    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(2);

    // Apply removes it.
    $this->artisan('launchpad:repair-coverage', ['site' => 'SPG', '--apply' => true])->assertSuccessful();
    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('state')->all())->toBe(['NJ']);
});

it('reports clean when there is nothing to fix', function () {
    $site = Site::factory()->create(['brand_name' => 'Clean', 'corporate_state' => 'NJ']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3402700010', 'name' => 'Morristown', 'state' => 'NJ']);

    $this->artisan('launchpad:repair-coverage', ['site' => 'Clean'])
        ->expectsOutputToContain('Coverage is clean')
        ->assertSuccessful();
});

it('fails on an unknown site', function () {
    $this->artisan('launchpad:repair-coverage', ['site' => 'nope'])->assertFailed();
});
