<?php

use App\Interview\Expansion\ExpansionValidator;
use App\Interview\Expansion\SiloExpander;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Tests\Support\ExpansionFixture;
use Tests\Support\FakeClaudeClient;

function siteWithSeed(): Site
{
    $site = Site::factory()->create();
    SiloBlueprint::factory()->create([
        'site_id' => $site->id,
        'trade' => 'waterproofing',
        'seed' => [
            'trade' => 'waterproofing',
            'anchor_services' => ['Sump Pump Installation'],
            'region' => 'NJ, eastern PA',
            'exclusions' => ['Roofing'],
        ],
    ]);

    return $site;
}

function fakeExpansion(): void
{
    // SiloExpander has a contextual ClaudeClient binding (the prefilled expander client),
    // so bind the expander itself with a fake to keep the command off the network.
    app()->instance(SiloExpander::class, new SiloExpander(new FakeClaudeClient(ExpansionFixture::json()), new ExpansionValidator));
}

test('dry-run prints the tree and writes nothing', function () {
    fakeExpansion();
    $site = siteWithSeed();

    $this->artisan('launchpad:silo-expand', ['site' => $site->id])
        ->expectsOutputToContain('Sump Pumps')
        ->expectsOutputToContain('Fringe handoff')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

test('--persist writes the draft blueprint', function () {
    fakeExpansion();
    $site = siteWithSeed();

    $this->artisan('launchpad:silo-expand', ['site' => $site->id, '--persist' => true])
        ->expectsOutputToContain('Persisted draft blueprint')
        ->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(16);
});

test('--json emits the raw tree', function () {
    fakeExpansion();
    $site = siteWithSeed();

    $this->artisan('launchpad:silo-expand', ['site' => $site->id, '--json' => true])
        ->expectsOutputToContain('"fringe_handoff"')
        ->assertSuccessful();
});

test('it fails on an unknown site', function () {
    fakeExpansion();
    $this->artisan('launchpad:silo-expand', ['site' => 'missing'])->assertFailed();
});

test('it fails when the site has no confirmed seed', function () {
    fakeExpansion();
    $site = Site::factory()->create(); // no blueprint/seed

    $this->artisan('launchpad:silo-expand', ['site' => $site->id])
        ->expectsOutputToContain('run the owner interview first')
        ->assertFailed();
});
