<?php

use App\Enums\ContentKind;
use App\Enums\SiteStatus;
use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\LaunchStep;
use App\Gathering\LaunchReadiness;
use App\Jobs\SyncSiloCategories;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Setup step 8 — Launch: the readiness checklist + the shared approve→build core (the guided
 * Plan's Approve machinery, now reachable from the new Setup).
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
});

/** A launch-ready tenant: seeded + generated structure, one stated service, Onboarding. */
function launchReadySite(): Site
{
    $site = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id, 'trade' => 'waterproofing', 'seed' => ['trade' => 'waterproofing']]);
    Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'name' => 'Sump Pumps',
        'is_pillar' => true, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage,
        'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service,
    ]);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);

    return $site;
}

it('registers as step 9 of the Setup group, flag-gated like the rest', function () {
    expect(LaunchStep::shouldRegisterNavigation())->toBeTrue()
        ->and(LaunchStep::getNavigationGroup())->toBe('Setup')
        ->and(LaunchStep::getNavigationSort())->toBe(9);

    config()->set('launchpad.new_setup_enabled', false);
    expect(LaunchStep::shouldRegisterNavigation())->toBeFalse();
});

it('the checklist is red-until-green and only structure / flags / services hard-gate', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $readiness = app(LaunchReadiness::class);

    // Bare tenant: everything open, the required trio blocks.
    $items = collect($readiness->checklist($site))->keyBy('key');
    expect($items['services']['ok'])->toBeFalse()
        ->and($items['structure']['ok'])->toBeFalse()
        ->and($items['services']['required'])->toBeTrue()
        ->and($items['voice']['required'])->toBeFalse() // advisory — thinner pages, legal launch
        ->and($readiness->canLaunch($site))->toBeFalse();

    // The Brand item deep-links the new Setup's Brand step (step 7).
    expect($items['brand']['url'])->toContain('/admin/setup2/brand');

    // Service + generated structure → launchable even before an explicit finalize
    // (generated-but-unconfirmed launches with the implicit as-arranged finalize).
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'S', 'name' => 'S', 'is_pillar' => true]);
    Service::factory()->create(['site_id' => $site->id]);

    $items = collect($readiness->checklist($site))->keyBy('key');
    expect($items['structure']['ok'])->toBeFalse()       // not confirmed yet…
        ->and($items['structure']['launch_ok'])->toBeTrue() // …but launch-legal
        ->and($readiness->canLaunch($site))->toBeTrue();
});

it('a blocked launch is a guarded no-op naming what is missing', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    session(['guided_site_id' => $site->id]);

    Livewire::test(LaunchStep::class)
        ->assertSee('blocked')
        ->call('launch')
        ->assertNotified();

    expect((bool) SetupState::query()->where('site_id', $site->id)->value('launched'))->toBeFalse()
        ->and($site->fresh()->status)->toBe(SiteStatus::Onboarding);
});

it('launch runs the shared approve→build core: config persisted, pages materialized, categories queued, site Active', function () {
    Queue::fake();
    $site = launchReadySite();

    Livewire::test(LaunchStep::class)
        ->assertSee('Ready to launch')
        ->set('localize', false)
        ->set('townPagePace', 8)
        ->set('freshContent', false)
        ->call('launch');

    $state = SetupState::query()->where('site_id', $site->id)->first();
    expect($state->approved)->toBeTrue()
        ->and($state->launched)->toBeTrue()
        ->and($state->build_status)->toBe('live')
        ->and($state->localize)->toBeFalse()
        ->and($state->town_page_pace)->toBe(8)
        ->and($state->fresh_content)->toBeFalse()
        ->and($site->fresh()->status)->toBe(SiteStatus::Active)
        // The implicit as-arranged finalize committed the blueprint…
        ->and(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first()->confirmed_at)->not->toBeNull()
        // …and the manifest materialized into planned page rows (no AI).
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('kind', ContentKind::Page->value)->count())->toBeGreaterThan(0);

    Queue::assertPushed(SyncSiloCategories::class, fn ($job) => $job->siteId === $site->id);

    // Idempotent — a re-run (business change → re-launch) is legal and keeps the state.
    Livewire::test(LaunchStep::class)->assertSee('Launched')->call('launch');
    expect(SetupState::query()->where('site_id', $site->id)->value('build_status'))->toBe('live');
});

it('a WordPress connection flips the advisory wordpress item green', function () {
    $site = launchReadySite();
    $items = collect(app(LaunchReadiness::class)->checklist($site))->keyBy('key');
    expect($items['wordpress']['ok'])->toBeFalse();

    Connection::factory()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);

    $items = collect(app(LaunchReadiness::class)->checklist($site))->keyBy('key');
    expect($items['wordpress']['ok'])->toBeTrue();
});
