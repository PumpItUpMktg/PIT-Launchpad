<?php

use App\Enums\UserRole;
use App\Filament\Pages\Guided\Inventory;
use App\Filament\Pages\Guided\Structure;
use App\Interview\Arrange\AutoArrangeRunner;
use App\Interview\Expansion\ExpansionPersister;
use App\Interview\Expansion\SiloExpander;
use App\Interview\Volume\VolumeGrounder;
use App\Jobs\BuildStructure;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'territory_done' => true,
    ]);
});

test('entering Structure with a seed but no spokes shows the building state and runs synchronously (no queue)', function () {
    Queue::fake();
    SiloBlueprint::factory()->create(['site_id' => $this->site->id, 'seed' => ['trade' => 'Waterproofing', 'anchor_services' => ['x']]]);

    Livewire::test(Structure::class)
        ->assertOk()
        ->assertSeeHtml('wire:init="runBuild"'); // the build runs in-request, not on a worker

    Queue::assertNothingPushed(); // no queued job — wire:init drives BuildStructure::dispatchSync
    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_status'))->toBe('building');
});

test('runBuild is a no-op once the structure is ready (idempotent, no re-run)', function () {
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id]);
    SetupState::query()->where('site_id', $this->site->id)->update(['structure_status' => 'ready']);

    Livewire::test(Structure::class)->call('runBuild'); // ready → returns early, no engine run

    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_status'))->toBe('ready');
});

test('re-entering Structure with spokes already present marks it ready and skips the build', function () {
    Queue::fake();
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id, 'seed' => ['trade' => 'Waterproofing']]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id]); // already built

    Livewire::test(Structure::class)->assertOk();

    Queue::assertNotPushed(BuildStructure::class); // idempotent — no re-build
    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_status'))->toBe('ready');
});

test('BuildStructure fails cleanly when there is no seed', function () {
    SiloBlueprint::factory()->create(['site_id' => $this->site->id, 'seed' => ['trade' => '']]);

    (new BuildStructure($this->site->id))->handle(
        app(SiloExpander::class),
        app(ExpansionPersister::class),
        app(VolumeGrounder::class),
        app(AutoArrangeRunner::class),
    );

    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_status'))->toBe('failed');
});

test('finalize commits the arranged tree and advances once ready with no flags', function () {
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Repair']);

    Livewire::test(Structure::class)
        ->call('finalize')
        ->assertRedirect(Inventory::getUrl());

    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_finalized'))->toBe(true)
        ->and($bp->fresh()->confirmed_at)->not->toBeNull();
});
