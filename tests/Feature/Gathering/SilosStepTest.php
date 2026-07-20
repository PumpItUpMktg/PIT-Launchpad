<?php

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Filament\Pages\SiloPrune;
use App\Filament\Pages\Targeting;
use App\Interview\Expansion\ExpansionValidator;
use App\Interview\Expansion\SiloExpander;
use App\Models\BlogTarget;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

/**
 * Setup step 7 — Silos & keywords (the GENERATE phase): steps 1–6 gather, this generates the
 * structure. Prune is a MODE inside the surface (not its own menu item); the continuous blog
 * target queue stays in Operate.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    config()->set('launchpad.new_setup_enabled', true);
});

/** A site with a generated candidate tree (same fixture shape the standalone prune tests use). */
function silosStepSite(): Site
{
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'waterproofing', 'anchor_services' => ['Sump Pumps']]]);
    $make = fn (array $a) => Spoke::factory()->create(array_merge([
        'site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps',
        'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage,
    ], $a));
    $make(['name' => 'Sump Pumps', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service]);
    $make(['name' => 'Sump Pump Installation', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'volume' => 300]);

    return $site;
}

it('is Setup step 8 (rail metadata kept); nav-final keeps it out of the sidebar; legacy items superseded', function () {
    // Nav-final: the single "Setup" entry is the only registered item — the step no longer shows in
    // the sidebar, but its group/sort/label metadata still drive the in-page stepper rail.
    expect(SilosStep::shouldRegisterNavigation())->toBeFalse()
        ->and(SilosStep::getNavigationGroup())->toBe('Setup')
        ->and(SilosStep::getNavigationSort())->toBe(8)
        ->and(SilosStep::getNavigationLabel())->toBe('Silos & keywords')
        // Structure lives in Setup now — the legacy standalone items leave the sidebar…
        ->and(Targeting::shouldRegisterNavigation())->toBeFalse()
        ->and(SiloPrune::shouldRegisterNavigation())->toBeFalse()
        ->and(Targeting::menuTag())->toBe('setup')
        ->and(SiloPrune::menuTag())->toBe('setup');

    // …and the flag-off promise holds: the old menu is exactly as before.
    config()->set('launchpad.new_setup_enabled', false);
    expect(SilosStep::shouldRegisterNavigation())->toBeFalse()
        ->and(Targeting::shouldRegisterNavigation())->toBeTrue()
        ->and(SiloPrune::shouldRegisterNavigation())->toBeTrue();
});

it('reads the gathered seed: empty without a trade, generate-ready with one, and the no-seed generate is a guarded no-op', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);

    $page = Livewire::test(SilosStep::class)->assertOk();
    expect($page->instance()->readiness()['state'])->toBe('empty');
    $page->call('generate')->assertNotified(); // no seed → warning, nothing runs
    expect(SetupState::query()->where('site_id', $site->id)->value('structure_status'))->toBeNull();

    // The Business-step trade (or interview extraction) seeds the blueprint → generate unlocks.
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'waterproofing']]);
    $page = Livewire::test(SilosStep::class)->assertOk()->assertSee('Build my plan');
    expect($page->instance()->readiness())->toBe(['state' => 'attention', 'label' => 'Seed ready — generate the structure']);
});

it('generate runs the structure chain synchronously and surfaces a failure as retryable state', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'waterproofing']]);
    // The expansion's Claude seam returns garbage → the chain fails cleanly (state, not a crash).
    // (SiloExpander is contextually bound to the factory's expander client, so fake the whole
    // expander instance — an instance() on ClaudeClient never reaches it.)
    app()->instance(SiloExpander::class, new SiloExpander(new FakeClaudeClient('not json at all'), new ExpansionValidator));

    Livewire::test(SilosStep::class)
        ->call('generate')
        ->assertOk()
        ->assertSee('try again');

    expect(SetupState::query()->where('site_id', $site->id)->value('structure_status'))->toBe('failed');
});

it('shows the silo cards with keyword targets and promote/demote adjusts the queue priority', function () {
    $site = silosStepSite();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $kw = Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'battery backup sump pump',
        'opportunity_score' => 10, 'priority' => 0, 'target_content_id' => null,
    ]);
    BlogTarget::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id]);

    Livewire::test(SilosStep::class)
        ->assertOk()
        ->assertSee('Sump Pumps')
        ->assertSee('battery backup sump pump')
        ->assertSee('1 blog idea(s) queued') // the seeded queue Operate's Blog drawer consumes
        ->call('promote', $kw->id);

    expect($kw->fresh()->priority)->toBe(1);
});

it('prune is a mode inside the surface: open → decide → finalize confirms the blueprint', function () {
    $site = silosStepSite();
    $install = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Sump Pump Installation')->first();

    $page = Livewire::test(SilosStep::class)
        ->assertSee('Review & approve')
        ->call('openPrune')
        ->assertSet('pruneMode', true)
        ->assertSet('started', true)
        ->assertSet("spokeDecisions.{$install->id}.outcome", 'offer') // pre-decided defaults, not a blank slate
        ->assertSee('pages to build');

    $page->call('finalize')->assertSet('finalized', true);

    expect(SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first()->confirmed_at)->not->toBeNull()
        ->and($page->instance()->readiness())->toBe(['state' => 'complete', 'label' => 'Structure confirmed']);

    // Back to the cards view; the step now offers a re-prune (real business change → regenerate, re-prune).
    $page->call('closePrune')->assertSet('pruneMode', false)->assertSee('Review again');
});

it('hand-files an unassigned keyword into a chosen silo (manual override for missed rule_set matches)', function () {
    $site = silosStepSite();
    $sump = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps']);
    $orphan = Keyword::factory()->create([
        'site_id' => $site->id, 'silo_id' => null, 'query' => 'battery backup sump pump installation',
    ]);

    $page = Livewire::test(SilosStep::class)->assertOk();
    // Silo options are offered for the move control…
    expect($page->instance()->siloOptions)->toContain('Sump Pumps');

    // …and moving files it under the chosen silo (silo_id set directly — no Bucketer).
    $page->call('assignKeywordToSilo', $orphan->id, $sump->id)->assertNotified();
    expect($orphan->fresh()->silo_id)->toBe($sump->id);

    // Unassign puts it back in the Unassigned band.
    $page->call('assignKeywordToSilo', $orphan->id, 'none');
    expect($orphan->fresh()->silo_id)->toBeNull();

    // The placeholder value is a no-op (doesn't null a filed keyword).
    $orphan->forceFill(['silo_id' => $sump->id])->save();
    $page->call('assignKeywordToSilo', $orphan->id, '');
    expect($orphan->fresh()->silo_id)->toBe($sump->id);
});

it('reads "ready" when a topic has a standalone service page, even before any search terms', function () {
    silosStepSite(); // Sump Pumps pillar + 'Sump Pump Installation' as an own-page service; no §4 keywords yet

    Livewire::test(SilosStep::class)
        ->assertOk()
        ->assertSee('ready')                 // page-aware: own-page service ⇒ ready
        ->assertDontSee('needs search terms');
});

it('reads "needs search terms" when a topic has only sections and no search terms', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'waterproofing']]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Odds & Ends', 'name' => 'Odds & Ends', 'is_pillar' => true, 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Odds & Ends', 'name' => 'Minor Thing', 'tag' => SpokeTag::Core, 'page_type' => SpokePageType::Service, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::Folded]);

    Livewire::test(SilosStep::class)->assertOk()->assertSee('needs search terms');
});

it('promotes a folded page into its own topic and syncs a §4 silo for it', function () {
    $site = silosStepSite(); // pillar 'Sump Pumps' + spoke 'Sump Pump Installation'
    $spoke = Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Sump Pump Installation')->first();

    Livewire::test(SilosStep::class)
        ->call('promoteToOwnTopic', $spoke->id)
        ->assertNotified();

    // The spoke now heads its own topic group…
    expect($spoke->fresh()->is_pillar)->toBeTrue()
        ->and($spoke->fresh()->silo)->toBe('Sump Pump Installation')
        // …and the §4 board has a matching silo (so it can take its own keyword targets).
        ->and(Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Sump Pump Installation')->exists())->toBeTrue();
});

it('will not move a keyword into another tenant\'s silo', function () {
    $site = silosStepSite();
    $mine = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'sump pump repair']);
    $otherSite = Site::factory()->create(['brand_name' => 'Other Tenant']);
    $foreignSilo = Silo::factory()->create(['site_id' => $otherSite->id, 'name' => 'Foreign Silo']);

    Livewire::test(SilosStep::class)->call('assignKeywordToSilo', $mine->id, $foreignSilo->id);

    expect($mine->fresh()->silo_id)->toBeNull(); // rejected — cross-tenant silo isn't a valid target
});

it('switching the working site drops out of prune mode with a clean decision-set', function () {
    $site = silosStepSite();
    $other = Site::factory()->create(['brand_name' => 'Other Tenant']);

    Livewire::test(SilosStep::class)
        ->call('openPrune')
        ->assertSet('pruneMode', true)
        ->call('setSite', $other->id)
        ->assertSet('pruneMode', false)
        ->assertSet('started', false)
        ->assertSet('spokeDecisions', []);
});

it('the generated tree is VISIBLE on the main view right after generate — no prune mode needed', function () {
    $site = silosStepSite(); // blueprint + pillar 'Sump Pumps' + core 'Sump Pump Installation' (vol 300)

    Livewire::test(SilosStep::class)
        ->assertOk()
        ->assertSee('Topic groups')                // the unified, plain-language silos header
        ->assertSee('Sump Pumps')                 // the silo card
        ->assertSee('Sump Pump Installation')     // its spoke, without entering prune
        ->assertSee('hub page')                   // pillar disposition label
        ->assertSee('own page')                   // core disposition label
        // Pages and search terms live in ONE card per silo, not two duplicate sections.
        ->assertSee('Pages we\'ll build', false) // escape:false — literal apostrophe in the markup
        ->assertSee('Search terms we target')
        // No §4 Silo/Keyword rows yet → the card's keyword block explains itself in plain words.
        ->assertSee('Find search terms')
        ->assertDontSee('No structure yet');
});
