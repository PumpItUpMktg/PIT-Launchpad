<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Integrations\Claude\ClaudeClient;
use App\Interview\Expansion\ExpansionValidator;
use App\Interview\Expansion\SiloExpander;
use App\Interview\SiloSeed;
use App\Jobs\BuildStructure;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\ExpansionFixture;
use Tests\Support\FakeClaudeClient;

it('SiloSeed carries the bounded-service list immutably', function () {
    $seed = new SiloSeed('waterproofing');
    expect($seed->isBounded())->toBeFalse();

    $bound = $seed->withBoundedServices(['Sump Pump Installation', 'French Drain Installation']);
    expect($bound->isBounded())->toBeTrue()
        ->and($bound->boundedServices)->toBe(['Sump Pump Installation', 'French Drain Installation'])
        ->and($seed->isBounded())->toBeFalse(); // original untouched
});

it('the expander emits a BOUNDED prompt listing the stated services when the seed is bounded', function () {
    $fake = new FakeClaudeClient(ExpansionFixture::json());
    $seed = (new SiloSeed('waterproofing', ['anchor'], 'NJ'))
        ->withBoundedServices(['Sump Pump Installation', 'French Drain Installation']);

    (new SiloExpander($fake, new ExpansionValidator))->expand($seed);

    expect($fake->prompts[0])->toContain('BOUNDED TO STATED SERVICES')
        ->toContain('Sump Pump Installation')
        ->toContain('French Drain Installation')
        ->toContain('COMPLETE and authoritative')
        ->toContain('Do NOT propose, invent'); // the hard rule that overrides "be generous"
});

it('a non-bounded seed keeps the generous prompt (no bounded block)', function () {
    $fake = new FakeClaudeClient(ExpansionFixture::json());

    (new SiloExpander($fake, new ExpansionValidator))->expand(new SiloSeed('waterproofing', ['anchor'], 'NJ'));

    expect($fake->prompts[0])->not->toContain('BOUNDED TO STATED SERVICES');
});

it('BuildStructure injects the stated services when bound_to_services is set on the seed', function () {
    $site = Site::factory()->create();
    SiloBlueprint::factory()->create([
        'site_id' => $site->id,
        'seed' => ['trade' => 'Waterproofing', 'anchor_services' => ['x'], 'bound_to_services' => true],
    ]);
    Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation']);
    Service::withoutGlobalScope(SiteScope::class)->create(['site_id' => $site->id, 'name' => 'French Drain Installation']);

    // SiloExpander has a contextual ClaudeClient binding (the factory's expander client), so we bind
    // the expander instance itself with the fake. expand() (and its prompt recording) runs BEFORE the
    // volume/arrange tail, and BuildStructure catches any tail error — so the prompt is captured.
    $fake = new FakeClaudeClient(ExpansionFixture::json());
    $this->app->instance(SiloExpander::class, new SiloExpander($fake, new ExpansionValidator));

    BuildStructure::dispatchSync($site->id);

    expect($fake->prompts)->not->toBeEmpty();
    expect($fake->prompts[0])->toContain('BOUNDED TO STATED SERVICES')
        ->toContain('Sump Pump Installation')
        ->toContain('French Drain Installation');
});

it('the Silos step toggles bound_to_services on the blueprint seed', function () {
    $site = Site::factory()->create();
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'Waterproofing']]);
    session(['guided_site_id' => $site->id]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Livewire::test(SilosStep::class)
        ->assertSet('boundToServices', false)   // computed getter, off by default
        ->call('toggleBoundToServices')
        ->assertNotified();

    expect((bool) (SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first()->seed['bound_to_services'] ?? false))->toBeTrue();
});
