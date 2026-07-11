<?php

use App\ContentEngine\Drafting\DraftCall;
use App\ContentEngine\Drafting\PageDrafter;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Service;
use App\Models\Silo;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\WireframeKit;
use App\Publishing\RenderCoordinator;
use App\Publishing\RenderOutcome;
use Database\Seeders\WireframeKitSeeder;
use Illuminate\Support\Collection;
use Tests\Support\Draft;
use Tests\Support\FakeClaudeClient;
use Tests\Support\PageFixture;

function gsSpoke(Site $site, array $overrides = []): Spoke
{
    return Spoke::factory()->create(array_merge([
        'site_id' => $site->id,
        'silo_blueprint_id' => SiloBlueprint::create(['site_id' => $site->id, 'trade' => 'plumbing'])->id,
        'name' => 'Sump Pump Installation',
        'silo' => 'Sump Pump Services',
        'primary_keyword' => 'sump pump installation',
    ], $overrides));
}

/** Materialize the spoke's page the way the Grow build does: a BuildPage linked to a Content row. */
function gsMaterialized(Site $site, Spoke $spoke, PageType $pageType, ?string $siloId = null): Content
{
    (new WireframeKitSeeder)->run();
    $kit = WireframeKit::query()->where('page_type', $pageType->value)->whereNull('site_id')->firstOrFail();

    $page = Content::factory()->create([
        'site_id' => $site->id,
        'silo_id' => $siloId,
        'kind' => ContentKind::Page,
        'page_type' => $pageType,
        'status' => ContentStatus::Candidate,
        'title' => (string) $spoke->name,
        'slug' => str($spoke->name)->slug()->toString(),
        'wireframe_kit_id' => $kit->id,
        'slot_payload' => [],
    ]);

    BuildPage::factory()->create([
        'site_id' => $site->id,
        'spoke_id' => $spoke->id,
        'content_id' => $page->id,
        'title' => (string) $spoke->name,
    ]);

    return $page;
}

function gsFakeDraftPath(Site $site): void
{
    app()->bind(PageDrafter::class, fn () => new PageDrafter(new DraftCall(new FakeClaudeClient(PageFixture::validResponse('none')))));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->andReturn(new RenderOutcome(new Collection, true, []));
    app()->instance(RenderCoordinator::class, $renders);
}

it('guards a spoke with no keyword, naming the fix', function () {
    $site = Site::factory()->create();
    $spoke = gsSpoke($site, ['primary_keyword' => null, 'head_keyword' => null]);

    test()->artisan('launchpad:generate-service', ['spoke' => $spoke->id])
        ->expectsOutputToContain('missing a name or a keyword')
        ->assertFailed();
});

it('guards a spoke with no hub (no silo membership)', function () {
    $site = Site::factory()->create();
    $spoke = gsSpoke($site, ['silo' => null, 'is_pillar' => false]);

    test()->artisan('launchpad:generate-service', ['spoke' => $spoke->id])
        ->expectsOutputToContain('no hub')
        ->assertFailed();
});

it('guards a spoke whose page has not been materialized yet', function () {
    $site = Site::factory()->create();
    $spoke = gsSpoke($site);

    test()->artisan('launchpad:generate-service', ['spoke' => $spoke->id])
        ->expectsOutputToContain('no materialized page')
        ->assertFailed();
});

it('guards a hub with no materialized child spoke pages', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    $pillar = gsSpoke($site, ['name' => 'Sump Pump Services', 'is_pillar' => true, 'silo' => null]);
    gsMaterialized($site, $pillar, PageType::Hub, $silo->id);

    test()->artisan('launchpad:generate-service', ['spoke' => $pillar->id])
        ->expectsOutputToContain('no materialized child spoke pages')
        ->assertFailed();
});

it('generates a SPOKE page for a service spoke (auto-detect) → needs_review', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation'])->silos()->attach($silo->id);
    $spoke = gsSpoke($site);
    $page = gsMaterialized($site, $spoke, PageType::Service, $silo->id);
    gsFakeDraftPath($site);

    test()->artisan('launchpad:generate-service', ['spoke' => $spoke->id])
        ->expectsOutputToContain('Spoke page:')
        ->assertSuccessful();

    expect($page->fresh()->status)->toBe(ContentStatus::NeedsReview);
});

it('generates a HUB page for a pillar spoke once a child spoke page exists', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Services']);
    Service::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pump Installation'])->silos()->attach($silo->id);

    $pillar = gsSpoke($site, ['name' => 'Sump Pump Services', 'is_pillar' => true, 'silo' => null, 'primary_keyword' => 'sump pump services']);
    $hubPage = gsMaterialized($site, $pillar, PageType::Hub, $silo->id);
    // A materialized child spoke page → the hub has something to route to.
    Content::factory()->create([
        'site_id' => $site->id, 'silo_id' => $silo->id, 'kind' => ContentKind::Page,
        'page_type' => PageType::Service, 'title' => 'Sump Pump Installation', 'slug' => 'sump-pump-installation',
    ]);

    app()->bind(PageDrafter::class, fn () => new PageDrafter(new DraftCall(new FakeClaudeClient(Draft::json([
        'slots' => [
            'hub_intro' => 'From new installations to battery backups, this is the full range of sump pump work we handle — sized to the basement, tested under load, and backed in writing.',
            'faq' => [
                ['question' => 'Which pump do I need?', 'answer' => 'We assess the pit and inflow first.'],
                ['question' => 'How fast can you install?', 'answer' => 'Usually one visit.'],
                ['question' => 'Do backups make sense?', 'answer' => 'If your area loses power in storms, yes.'],
            ],
        ],
    ])))));
    $renders = Mockery::mock(RenderCoordinator::class);
    $renders->shouldReceive('render')->andReturn(new RenderOutcome(new Collection, true, []));
    app()->instance(RenderCoordinator::class, $renders);

    test()->artisan('launchpad:generate-service', ['spoke' => $pillar->id])
        ->expectsOutputToContain('Hub page:')
        ->assertSuccessful();

    expect($hubPage->fresh()->status)->toBe(ContentStatus::NeedsReview);
});
