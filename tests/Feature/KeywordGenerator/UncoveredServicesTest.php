<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
    config()->set('launchpad.keyword_first.enabled', true);
});

function flaggedService(Site $site, string $name): Service
{
    $service = Service::factory()->create(['site_id' => $site->id, 'name' => $name, 'short_description' => "About {$name}."]);
    $service->forceFill(['structure_home_flagged' => true])->save();

    return $service;
}

it('surfaces stated services the demand-first plan gave no page', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    flaggedService($site, 'Mold Testing');
    flaggedService($site, 'Water Damage Cleanup');
    // A service that DID map to a real cluster is not flagged → not surfaced.
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);

    $report = Livewire::test(SilosStep::class)->instance()->uncoveredServices;

    expect($report)->toHaveCount(2)
        ->and(collect($report)->pluck('name')->all())->toContain('Mold Testing', 'Water Damage Cleanup')
        ->and(collect($report)->pluck('name')->all())->not->toContain('Sump Pump Installation');
});

it('files an uncovered service into the chosen topic as its own page and clears the flag', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Foundation Water Problems']);
    $service = flaggedService($site, 'Mold Testing');

    Livewire::test(SilosStep::class)
        ->set("uncoveredSilo.{$service->id}", $silo->id)
        ->set("uncoveredKind.{$service->id}", 'own_page')
        ->call('addServiceAsSpoke', $service->id);

    $spoke = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Mold Testing')->first();
    expect($spoke)->not->toBeNull()
        ->and($spoke->silo)->toBe('Foundation Water Problems')
        ->and($spoke->granularity)->toBe(SpokeGranularity::OwnPage)
        ->and($spoke->status)->toBe(SpokeStatus::Offered)
        ->and($spoke->is_pillar)->toBeFalse()
        ->and($service->fresh()->structure_home_flagged)->toBeFalse()
        // Filing an own page guarantees it survives a rebuild.
        ->and($service->fresh()->force_page)->toBeTrue()
        ->and($service->fresh()->forced_silo)->toBe('Foundation Water Problems');

    // Covered now → drops off the report.
    expect(Livewire::test(SilosStep::class)->instance()->uncoveredServices)->toBe([]);
});

it('files an uncovered service as a mention (folded section) when chosen', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drainage Solutions']);
    $service = flaggedService($site, 'Basement Dehumidifier Installation');

    Livewire::test(SilosStep::class)
        ->set("uncoveredSilo.{$service->id}", $silo->id)
        ->set("uncoveredKind.{$service->id}", 'folded')
        ->call('addServiceAsSpoke', $service->id);

    $spoke = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Basement Dehumidifier Installation')->first();
    expect($spoke->granularity)->toBe(SpokeGranularity::Folded);
});

it('refuses to add without a chosen topic (no spoke created)', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::withoutGlobalScopes()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    $service = flaggedService($site, 'Mold Testing');

    Livewire::test(SilosStep::class)->call('addServiceAsSpoke', $service->id);

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0)
        ->and($service->fresh()->structure_home_flagged)->toBeTrue(); // still flagged
});
