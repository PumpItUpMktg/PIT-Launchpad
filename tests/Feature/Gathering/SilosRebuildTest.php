<?php

use App\Enums\SpokeTag;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Interview\Expansion\ExpansionValidator;
use App\Interview\Expansion\SiloExpander;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\ExpansionFixture;
use Tests\Support\FakeClaudeClient;

beforeEach(function () {
    session(['guided_site_id' => null]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('rebuildStructure clears the existing tree and re-expands, honoring the bound-to-services flag', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    $bp = SiloBlueprint::factory()->create([
        'site_id' => $site->id,
        'seed' => ['trade' => 'Waterproofing', 'anchor_services' => ['x'], 'bound_to_services' => true],
    ]);
    // An existing tree — plain "Re-ground & re-arrange" would skip the expand entirely.
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Old', 'name' => 'Old', 'is_pillar' => true, 'tag' => SpokeTag::Core]);
    Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);

    $fake = new FakeClaudeClient(ExpansionFixture::json());
    $this->app->instance(SiloExpander::class, new SiloExpander($fake, new ExpansionValidator));

    Livewire::test(SilosStep::class)->call('rebuildStructure')->assertNotified();

    // Re-expand actually ran (a plain re-ground never calls the expander)…
    expect($fake->prompts)->not->toBeEmpty()
        // …and it was bounded to the stated services.
        ->and($fake->prompts[0])->toContain('BOUNDED TO STATED SERVICES')
        ->and($fake->prompts[0])->toContain('Sump Pump Installation');
});

it('rebuildStructure syncs the §4 board to the rebuilt tree — creates current silos, reconciles stale ones, derives rule_sets', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create([
        'site_id' => $site->id,
        'seed' => ['trade' => 'Waterproofing', 'anchor_services' => ['x']],
    ]);
    // A §4 silo left over from an EARLIER structure — not in the fixture tree, so it must be reconciled away.
    Silo::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Old Stale Silo', 'type' => 'service_pillar']);

    $fake = new FakeClaudeClient(ExpansionFixture::json());
    $this->app->instance(SiloExpander::class, new SiloExpander($fake, new ExpansionValidator));

    Livewire::test(SilosStep::class)->call('rebuildStructure')->assertNotified();

    $silos = Silo::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();

    // The board now mirrors the fixture tree's silos — the stale one is gone.
    expect($silos->pluck('name')->sort()->values()->all())->toBe([
        'Brands We Service', 'Commercial & Industrial', 'Sump Pumps', 'Waterproofing & Drainage',
    ])
        ->and($silos->pluck('name')->all())->not->toContain('Old Stale Silo')
        // …and each carries a rule_set so §5 discovery / re-file can route keywords into it.
        ->and($silos->every(fn (Silo $s) => ! empty($s->rule_set['include_patterns'] ?? [])))->toBeTrue();
});

it('rebuildStructure warns and no-ops without a seed', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => []]); // no trade

    Livewire::test(SilosStep::class)->call('rebuildStructure')->assertNotified();

    expect(Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});
