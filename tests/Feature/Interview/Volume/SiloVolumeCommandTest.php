<?php

use App\Enums\MunicipalityType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Support\Facades\Http;

// The command resolves VolumeGrounder via the container → the real file-backed DmaTable
// (NJ county 34003 → "New York,NY,United States").
function fakeCommandVolume(): void
{
    Http::fake(fn ($request) => Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [
        ['keyword' => 'sump pump installation', 'search_volume' => 480],
    ]]]]));
}

function commandSite(): Site
{
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400312345', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'name' => 'Sump Pump Installation', 'head_keyword' => 'sump pump installation', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Candidate, 'volume' => null]);

    return $site;
}

test('it grounds, prints the metro + tree, and persists volume', function () {
    fakeCommandVolume();
    $site = commandSite();

    $this->artisan('launchpad:silo-volume', ['site' => $site->id])
        ->expectsOutputToContain('New York,NY')
        ->expectsOutputToContain('Sump Pump Installation')
        ->assertSuccessful();

    $spoke = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect($spoke->volume)->toBe(480)
        ->and($spoke->volume_at)->not->toBeNull();
});

test('--json emits the grounded tree', function () {
    fakeCommandVolume();
    $site = commandSite();

    $this->artisan('launchpad:silo-volume', ['site' => $site->id, '--json' => true])
        ->expectsOutputToContain('"breakdown"')
        ->assertSuccessful();
});

test('it fails on an unknown site', function () {
    fakeCommandVolume();
    $this->artisan('launchpad:silo-volume', ['site' => 'missing'])->assertFailed();
});

test('it fails (no spend) when coverage is missing', function () {
    fakeCommandVolume();
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'head_keyword' => 'x', 'status' => SpokeStatus::Candidate]);

    $this->artisan('launchpad:silo-volume', ['site' => $site->id])
        ->expectsOutputToContain('No covered metros')
        ->assertFailed();
});
