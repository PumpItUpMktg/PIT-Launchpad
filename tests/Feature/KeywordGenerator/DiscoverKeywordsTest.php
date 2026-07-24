<?php

use App\Enums\PipelineTrigger;
use App\Enums\UserRole;
use App\Filament\Pages\Gathering\SilosStep;
use App\Jobs\DiscoverKeywords;
use App\KeywordGenerator\Pipeline\SitePipelineRefresher;
use App\KeywordGenerator\Pipeline\SitePipelineRefreshResult;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('the command forces a discovery run that GENERATES, and reports both counts', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    $refresher = Mockery::mock(SitePipelineRefresher::class);
    $refresher->shouldReceive('refresh')
        ->once()
        ->withArgs(fn (Site $s, PipelineTrigger $t, bool $force, bool $generate) => $s->id === $site->id && $t === PipelineTrigger::Manual && $force === true && $generate === true)
        ->andReturn(new SitePipelineRefreshResult(discoveryRan: true, keywordsScored: 12, trackingRan: false, snapshots: 0, keywordsGenerated: 40));
    $this->app->instance(SitePipelineRefresher::class, $refresher);

    $this->artisan('launchpad:discover-keywords', ['--site' => $site->id])
        ->expectsOutputToContain('40 generated, 12 keyword(s) scored')
        ->assertSuccessful();
});

it('the job runs a forced, GENERATING discovery refresh for its site', function () {
    $site = Site::factory()->create();

    $refresher = Mockery::mock(SitePipelineRefresher::class);
    $refresher->shouldReceive('refresh')
        ->once()
        ->withArgs(fn (Site $s, PipelineTrigger $t, bool $force, bool $generate) => $s->id === $site->id && $force === true && $generate === true)
        ->andReturn(new SitePipelineRefreshResult(true, 3, false, 0, 9));

    (new DiscoverKeywords($site->id))->handle($refresher);
});

it('the Silos step action QUEUES discovery off the web request (never runs the provider storm in-request)', function () {
    Queue::fake();
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id]);
    session(['guided_site_id' => $site->id]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    // The pull is a storm of real DataForSEO + Claude calls; running it in-request 500s the page
    // (FPM timeout). The action must DISPATCH the job to the worker, not run it inline.
    Livewire::test(SilosStep::class)
        ->call('discoverKeywords')
        ->assertNotified();

    Queue::assertPushed(DiscoverKeywords::class, fn (DiscoverKeywords $job) => $job->siteId === $site->id);
});

it('the discover button shows once structure exists', function () {
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id]);
    session(['guided_site_id' => $site->id]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    Livewire::test(SilosStep::class)->assertSee('Find search terms');

    // No structure yet → no discover button (nothing to fill).
    $bare = Site::factory()->create();
    Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $bare->id)->delete();
    session(['guided_site_id' => $bare->id]);
    Livewire::test(SilosStep::class)->assertDontSee('Find search terms');
});
