<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Filament\Client\Pages\PagePlan;
use App\Models\SiloBlueprint;
use App\Models\Spoke;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

test('the page plan renders the client site inventory and the client can sign off', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'name' => 'Sump Pumps',
        'is_pillar' => true, 'status' => SpokeStatus::Candidate, 'page_type' => SpokePageType::Service,
        'tag' => SpokeTag::Core, 'granularity' => SpokeGranularity::OwnPage, 'volume' => 300,
    ]);

    Livewire::test(PagePlan::class)
        ->assertOk()
        ->assertSee('Sump Pumps')
        ->call('approve')
        ->assertOk();

    expect($bp->refresh()->isClientApproved())->toBeTrue();
});

test('the page plan is white-labeled and read-only for the client (no blueprint = graceful empty)', function () {
    ['user' => $client] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    Livewire::test(PagePlan::class)->assertOk();
});

test('the page-plan view frames volume as search demand, never promised or attributed leads', function () {
    $raw = file_get_contents(resource_path('views/filament/client/pages/page-plan.blade.php'));
    $lower = strtolower($raw);

    expect($lower)->toContain('search')
        ->not->toContain('guaranteed')
        ->not->toContain('drove')
        ->not->toContain('caused')
        ->not->toContain('promised lead');
    expect($raw)->not->toContain('ROI'); // raw, so 'heroicon' doesn't false-match
});
