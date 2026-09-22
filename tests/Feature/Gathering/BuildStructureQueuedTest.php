<?php

use App\Filament\Pages\Gathering\SilosStep;
use App\Interview\Expansion\ExpansionValidator;
use App\Interview\Expansion\SiloExpander;
use App\Jobs\BuildStructure;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeClaudeClient;

afterEach(function () {
    CurrentSite::clear();
});

it('queues the build instead of running it inside the request, and says so', function () {
    Queue::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'auto repair']]);

    // On Miller Auto & Tire the synchronous build died on the request timeout with nothing caught: a
    // blank "error while loading" and a status stuck at building. Queued, the page can tell the truth.
    Livewire::test(SilosStep::class)
        ->call('generate')
        ->assertNotified('Building your plan');

    Queue::assertPushed(BuildStructure::class, fn (BuildStructure $j): bool => $j->siteId === (string) $site->id);
    expect(SetupState::query()->where('site_id', $site->id)->value('structure_status'))->toBe('building');
});

it('shows a polling building state while the worker has it', function () {
    Queue::fake();
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'auto repair']]);
    SetupState::query()->create(['site_id' => $site->id, 'structure_status' => 'building']);

    Livewire::test(SilosStep::class)
        ->assertSee('Building your plan')
        ->assertSeeHtml('wire:poll')
        ->assertDontSee('Build my plan');
});

it('shows the stamped reason after a failed build, not a bare retry', function () {
    $site = Site::factory()->create();
    session(['guided_site_id' => $site->id]);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'auto repair']]);
    SetupState::query()->create([
        'site_id' => $site->id, 'structure_status' => 'failed', 'structure_error' => 'Expansion returned no silos',
    ]);

    Livewire::test(SilosStep::class)
        ->assertSee('try again')
        ->assertSee('Expansion returned no silos');
});

it('builds from the console with no request clock and prints the stamped outcome', function () {
    $site = Site::factory()->create(['brand_name' => 'Miller Auto & Tire']);
    SiloBlueprint::factory()->create(['site_id' => $site->id, 'seed' => ['trade' => 'auto repair']]);
    // The expansion's Claude seam returns garbage → the job stamps failed with a reason, and the command
    // prints that reason rather than a spinner that never resolves.
    app()->instance(SiloExpander::class, new SiloExpander(new FakeClaudeClient('not json at all'), new ExpansionValidator));

    $this->artisan('launchpad:build-structure', ['--site' => 'Miller Auto & Tire'])
        ->expectsOutputToContain('building the structure now')
        ->expectsOutputToContain('Failed after')
        ->assertFailed();

    expect(SetupState::query()->where('site_id', $site->id)->value('structure_status'))->toBe('failed');
});

it('refuses to build without a site', function () {
    $this->artisan('launchpad:build-structure')->assertFailed();
});
