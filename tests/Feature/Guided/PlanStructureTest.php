<?php

use App\Enums\UserRole;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Plan;
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

/**
 * The structure engine inside the merged Plan step (setup-redesign relay): the old Structure
 * step's building/ready/failed machine now runs on Plan entry, and finalize is implicit in
 * Approve.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 5, 'services_done' => true, 'territory_done' => true,
    ]);
});

test('entering the Plan with a seed but no spokes shows the building state and runs synchronously (no queue)', function () {
    Queue::fake();
    SiloBlueprint::factory()->create(['site_id' => $this->site->id, 'seed' => ['trade' => 'Waterproofing', 'anchor_services' => ['x']]]);

    Livewire::test(Plan::class)
        ->assertOk()
        ->assertSeeHtml('wire:init="runBuild"') // the build runs in-request, not on a worker
        ->assertSeeHtml('wire:loading.flex wire:target="runBuild"') // visible spinner while in flight
        ->assertSee('Planning your website…'); // the indicator copy

    Queue::assertNothingPushed(); // no queued job — wire:init drives BuildStructure::dispatchSync
    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_status'))->toBe('building');
});

test('runBuild is a no-op once the structure is ready (idempotent, no re-run)', function () {
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id]);
    SetupState::query()->where('site_id', $this->site->id)->update(['structure_status' => 'ready']);

    Livewire::test(Plan::class)->call('runBuild'); // ready → returns early, no engine run

    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_status'))->toBe('ready');
});

test('re-entering the Plan with spokes already present marks it ready and skips the build', function () {
    Queue::fake();
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id, 'seed' => ['trade' => 'Waterproofing']]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id]); // already built

    Livewire::test(Plan::class)->assertOk();

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

test('approve commits the arranged tree (implicit finalize) and hands off once ready with no flags', function () {
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Repair']);

    Livewire::test(Plan::class)
        ->call('approveAndBuild')
        ->assertRedirect(Grow::getUrl());

    expect(SetupState::query()->where('site_id', $this->site->id)->value('structure_finalized'))->toBe(true)
        ->and($bp->fresh()->confirmed_at)->not->toBeNull();
});

test('the arranged tree renders inside the collapsed Adjust-structure panel', function () {
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Repair']);

    Livewire::test(Plan::class)
        ->assertOk()
        ->assertSee('Adjust structure')
        ->assertSee('most owners never need this')
        ->assertSee('Repair');
});
