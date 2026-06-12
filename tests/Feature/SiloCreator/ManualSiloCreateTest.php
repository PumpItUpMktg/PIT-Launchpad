<?php

use App\Enums\SiloType;
use App\Enums\UserRole;
use App\Filament\Resources\SiloManagementResource\Pages\CreateSilo;
use App\Filament\Resources\SiloManagementResource\Pages\ListSilos;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function siloCount(Site $site): int
{
    return Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count();
}

it('offers a New silo action on the Silos list', function () {
    Livewire::test(ListSilos::class)->assertActionExists('create');
});

it('commits a manual silo through SiloCommitter — pillar stub + seeded rule_set', function () {
    $site = Site::factory()->create();

    Livewire::test(CreateSilo::class)
        ->fillForm([
            'site_id' => $site->id,
            'name' => 'Water Heaters',
            'type' => SiloType::ServicePillar->value,
            'seed_terms' => ['water heater repair', 'tankless water heater'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $silo = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();

    expect($silo->name)->toBe('Water Heaters')
        ->and($silo->type)->toBe(SiloType::ServicePillar)
        ->and($silo->rule_set['seed_terms'])->toBe(['water heater repair', 'tankless water heater'])
        ->and($silo->pillar_content_id)->not->toBeNull(); // pillar stub linked (not a raw row)

    // The pillar Content stub exists and is pinned.
    $pillar = Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->firstOrFail();
    expect($pillar->id)->toBe($silo->pillar_content_id)
        ->and($pillar->silo_id)->toBe($silo->id);
});

it('halts a geo-tainted silo — nothing is written', function () {
    $site = Site::factory()->create();

    Livewire::test(CreateSilo::class)
        ->fillForm([
            'site_id' => $site->id,
            'name' => 'Texas Plumbing', // "texas" is a geo term — the §4 hard rule
            'type' => SiloType::ServicePillar->value,
            'seed_terms' => ['plumbing'],
        ])
        ->call('create');

    expect(siloCount($site))->toBe(0)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

it('requires a tenant, name and at least one seed term', function () {
    Livewire::test(CreateSilo::class)
        ->fillForm(['site_id' => null, 'name' => null, 'seed_terms' => []])
        ->call('create')
        ->assertHasFormErrors(['site_id', 'name', 'seed_terms']);
});
