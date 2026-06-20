<?php

use App\Enums\ContentStatus;
use App\Enums\SpokeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Build;
use App\Filament\Pages\Guided\Grow;
use App\Guided\GrowDashboard;
use App\Jobs\BuildStructure;
use App\Models\BuildPage;
use App\Models\Content;
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
});

test('Step 4 persists build config, approves, assembles the manifest, and hands off to Build', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 4,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true,
    ]);
    SiloBlueprint::factory()->create(['site_id' => $this->site->id]); // a structure to plan over

    Livewire::test(Approve::class)
        ->set('localize', false)
        ->set('townPagePace', 8)
        ->set('freshContent', false)
        ->call('approveAndBuild')
        ->assertRedirect(Build::getUrl());

    $state = SetupState::query()->where('site_id', $this->site->id)->first();
    expect($state->approved)->toBeTrue()
        ->and($state->launched)->toBeFalse()          // Build sets launched
        ->and($state->build_status)->toBe('building')
        ->and($state->localize)->toBeFalse()
        ->and($state->town_page_pace)->toBe(8)
        ->and($state->fresh_content)->toBeFalse()
        ->and(BuildPage::query()->where('site_id', $this->site->id)->count())->toBe(6); // fixed core
});

test('the Grow dashboard counts live / building / planned and lists fresh posts', function () {
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Content::factory()->create(['site_id' => $this->site->id, 'status' => ContentStatus::Published]);
    Content::factory()->create(['site_id' => $this->site->id, 'status' => ContentStatus::Rendering]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'status' => SpokeStatus::Offered]);

    $stats = app(GrowDashboard::class)->stats($this->site);

    expect($stats['live'])->toBe(1)
        ->and($stats['building'])->toBe(1)
        ->and($stats['planned'])->toBe(1);
});

test('Grow re-run controls: re-arrange runs inline, re-ground dispatches the build', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 6,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true, 'approved' => true, 'launched' => true,
    ]);
    Queue::fake();

    Livewire::test(Grow::class)
        ->assertOk()
        ->call('reArrange')->assertOk()
        ->call('reGround')->assertOk();

    Queue::assertPushed(BuildStructure::class);
});
