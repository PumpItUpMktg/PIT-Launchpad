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
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'name' => 'Curtain Drain', 'tag' => SpokeTag::Adjacent, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate, 'volume' => 20]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Out of Lane', 'name' => 'General Plumbing', 'tag' => SpokeTag::Fringe, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate]);

    return $site;
}

test('silo-prune prints the grouped, summarized plan with the fringe handoff', function () {
    $site = commandPruneSite();

    $this->artisan('launchpad:silo-prune', ['site' => $site->id])
        ->expectsOutputToContain('lean-ins')          // per-silo summary
        ->expectsOutputToContain('Fringe handoff')
        ->assertSuccessful();
});

test('silo-prune --json emits the plan', function () {
    $site = commandPruneSite();

    $this->artisan('launchpad:silo-prune', ['site' => $site->id, '--json' => true])
        ->expectsOutputToContain('"pending"')
        ->assertSuccessful();
});

test('prune-apply applies a decision-set file and confirms', function () {
    $site = commandPruneSite();
    $path = sys_get_temp_dir().'/prune-'.uniqid().'.json';
    file_put_contents($path, (string) json_encode([
        'spokes' => [
            'Sump Pump Installation' => ['outcome' => 'offer'],
            'Curtain Drain' => ['outcome' => 'skip'],
        ],
    ]));

    $this->artisan('launchpad:prune-apply', ['site' => $site->id, 'decisions' => $path, '--confirm' => true])
        ->expectsOutputToContain('Applied 2 spoke decision')
        ->expectsOutputToContain('CONFIRMED')
        ->assertSuccessful();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('name', 'Sump Pump Installation')->value('status'))->toBe(SpokeStatus::Offered)
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->value('confirmed_at'))->not->toBeNull();
    @unlink($path);
});

test('prune-apply --confirm is blocked while candidates are pending', function () {
    $site = commandPruneSite();
    $path = sys_get_temp_dir().'/prune-'.uniqid().'.json';
    file_put_contents($path, (string) json_encode(['spokes' => ['Sump Pump Installation' => 'offer']]));

    $this->artisan('launchpad:prune-apply', ['site' => $site->id, 'decisions' => $path, '--confirm' => true])
        ->expectsOutputToContain('Cannot confirm')
        ->assertSuccessful();
    @unlink($path);
});

test('prune-apply fails on a missing decisions file', function () {
    $site = commandPruneSite();

    $this->artisan('launchpad:prune-apply', ['site' => $site->id, 'decisions' => '/no/such/file.json'])
        ->expectsOutputToContain('not found')
        ->assertFailed();
});

test('silo-prune fails on an unknown site', function () {
    $this->artisan('launchpad:silo-prune', ['site' => 'missing'])->assertFailed();
});
