<?php

use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

function commandPruneSite(): Site
{
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'name' => 'Sump Pump Installation', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate, 'volume' => 480]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Out of Lane', 'name' => 'General Plumbing', 'tag' => SpokeTag::Fringe, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate]);

    return $site;
}

test('it prints the plan with pending candidates and the fringe handoff', function () {
    $site = commandPruneSite();

    $this->artisan('launchpad:silo-prune', ['site' => $site->id])
        ->expectsOutputToContain('Sump Pump Installation') // pending row
        ->expectsOutputToContain('Fringe handoff')
        ->assertSuccessful();
});

test('--accept-core then --confirm locks the blueprint', function () {
    $site = commandPruneSite();

    $this->artisan('launchpad:silo-prune', ['site' => $site->id, '--accept-core' => true, '--confirm' => true])
        ->expectsOutputToContain('CONFIRMED')
        ->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('name', 'Sump Pump Installation')->value('status'))->toBe(SpokeStatus::Offered)
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->not->toBeNull();
});

test('--apply routes from a decisions file', function () {
    $site = commandPruneSite();
    $path = sys_get_temp_dir().'/prune-'.uniqid().'.json';
    file_put_contents($path, (string) json_encode(['Sump Pump Installation' => 'capture']));

    $this->artisan('launchpad:silo-prune', ['site' => $site->id, '--apply' => $path])
        ->expectsOutputToContain('Applied 1 decision')
        ->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('name', 'Sump Pump Installation')->value('page_type'))->toBe(SpokePageType::Content);
    @unlink($path);
});

test('--confirm is blocked while candidates are pending', function () {
    $site = commandPruneSite();

    $this->artisan('launchpad:silo-prune', ['site' => $site->id, '--confirm' => true])
        ->expectsOutputToContain('Cannot confirm')
        ->assertSuccessful();
});

test('it fails on an unknown site', function () {
    $this->artisan('launchpad:silo-prune', ['site' => 'missing'])->assertFailed();
});
