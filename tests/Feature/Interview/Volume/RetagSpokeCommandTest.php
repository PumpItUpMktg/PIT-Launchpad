<?php

use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function retagSpoke(Site $site, string $name, string $keyword): Spoke
{
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);

    return Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'name' => $name, 'head_keyword' => $keyword]);
}

test('it retargets a spoke head_keyword (case-insensitive name match)', function () {
    $site = Site::factory()->create();
    $spoke = retagSpoke($site, 'Basement Dehumidification', 'basement dehumidifier');

    $this->artisan('launchpad:retag-spoke', ['site' => $site->id, 'name' => 'basement dehumidification', 'keyword' => 'basement dehumidification service'])
        ->expectsOutputToContain('→ "basement dehumidification service"')
        ->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->find($spoke->id)->head_keyword)->toBe('basement dehumidification service');
});

test('it fails on an unknown site', function () {
    $this->artisan('launchpad:retag-spoke', ['site' => 'missing', 'name' => 'x', 'keyword' => 'y'])->assertFailed();
});

test('it fails when no spoke matches the name', function () {
    $site = Site::factory()->create();

    $this->artisan('launchpad:retag-spoke', ['site' => $site->id, 'name' => 'Nope', 'keyword' => 'y'])
        ->expectsOutputToContain('No spoke named')
        ->assertFailed();
});

test('it refuses an ambiguous name (two spokes) rather than guessing', function () {
    $site = Site::factory()->create();
    retagSpoke($site, 'Water Leak Detection', 'a');
    retagSpoke($site, 'Water Leak Detection', 'b');

    $this->artisan('launchpad:retag-spoke', ['site' => $site->id, 'name' => 'Water Leak Detection', 'keyword' => 'water leak detection service'])
        ->expectsOutputToContain('Ambiguous')
        ->assertFailed();
});

test('it is scoped to the given site (does not touch another site\'s spoke)', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    retagSpoke($a, 'Shared Name', 'a-keyword');
    $bSpoke = retagSpoke($b, 'Shared Name', 'b-keyword');

    $this->artisan('launchpad:retag-spoke', ['site' => $a->id, 'name' => 'Shared Name', 'keyword' => 'new'])->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->find($bSpoke->id)->head_keyword)->toBe('b-keyword'); // untouched
});
