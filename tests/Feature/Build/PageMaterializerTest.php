<?php

use App\Build\PageMaterializer;
use App\Build\Permalinks;
use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Database\Seeders\WireframeKitSeeder;

function manifestEntry(Site $site, BuildSource $source, string $key, string $title, array $extra = []): BuildPage
{
    return BuildPage::factory()->create(array_merge([
        'site_id' => $site->id,
        'source' => $source,
        'page_key' => $key,
        'title' => $title,
        'recipe' => 'x',
        'status' => BuildStatus::Queued,
        'priority' => 100,
        'review_required' => false,
        'spoke_id' => null,
    ], $extra));
}

function pagesFor(Site $site)
{
    return Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get();
}

test('materialize creates one undrafted page per manifest entry, no AI, with unique permalinks', function () {
    $site = Site::factory()->create();
    manifestEntry($site, BuildSource::Standard, 'home', 'Home');
    manifestEntry($site, BuildSource::Standard, 'about', 'About');
    manifestEntry($site, BuildSource::Location, 'twn1', 'Springfield, IL');

    $pages = app(PageMaterializer::class)->materialize($site);

    expect($pages)->toHaveCount(3)
        ->and(pagesFor($site)->count())->toBe(3);

    $all = pagesFor($site);
    expect($all->every(fn (Content $c) => $c->kind === ContentKind::Page))->toBeTrue()
        ->and($all->every(fn (Content $c) => $c->status === ContentStatus::Candidate))->toBeTrue()
        ->and($all->every(fn (Content $c) => ! $c->hasDraft()))->toBeTrue()           // planned/undrafted — no AI ran
        ->and($all->pluck('slug')->unique()->count())->toBe(3)                         // unique permalinks
        ->and($all->every(fn (Content $c) => $c->slug !== null && $c->slug !== ''))->toBeTrue();

    // every manifest entry is linked to its page (the idempotency key)
    expect(BuildPage::query()->where('site_id', $site->id)->whereNull('content_id')->count())->toBe(0);
});

test('materialize is idempotent — re-running does not duplicate', function () {
    $site = Site::factory()->create();
    manifestEntry($site, BuildSource::Standard, 'home', 'Home');
    manifestEntry($site, BuildSource::Standard, 'about', 'About');

    $materializer = app(PageMaterializer::class);
    $materializer->materialize($site);
    $firstIds = pagesFor($site)->pluck('id')->sort()->values();

    $materializer->materialize($site); // re-run (e.g. operator re-approves)

    expect(pagesFor($site)->count())->toBe(2)
        ->and(pagesFor($site)->pluck('id')->sort()->values()->all())->toBe($firstIds->all());
});

test('page_type is mapped per source: standard, service own-page/pillar, location', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $pillar = Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => true]);
    $core = Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => false]);

    manifestEntry($site, BuildSource::Standard, 'home', 'Home');
    manifestEntry($site, BuildSource::Standard, 'about', 'About');
    manifestEntry($site, BuildSource::Service, $pillar->id, 'Plumbing', ['spoke_id' => $pillar->id]);
    manifestEntry($site, BuildSource::Service, $core->id, 'Drain Cleaning', ['spoke_id' => $core->id]);
    manifestEntry($site, BuildSource::Location, 'twn1', 'Austin, TX');

    app(PageMaterializer::class)->materialize($site);

    $byTitle = pagesFor($site)->keyBy('title');
    expect($byTitle['Home']->page_type)->toBe(PageType::Home)
        ->and($byTitle['About']->page_type)->toBe(PageType::Utility)
        ->and($byTitle['Plumbing']->page_type)->toBe(PageType::Hub)         // pillar spoke → hub
        ->and($byTitle['Drain Cleaning']->page_type)->toBe(PageType::Service)
        ->and($byTitle['Austin, TX']->page_type)->toBe(PageType::Location);
});

test('a service page is pinned to its OWN projected service; hub / standard / location pages are not', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $pillar = Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => true, 'name' => 'Plumbing']);
    $service = Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => false, 'name' => 'Toilet Replacement', 'silo' => 'Plumbing']);

    manifestEntry($site, BuildSource::Service, $pillar->id, 'Plumbing', ['spoke_id' => $pillar->id]);
    manifestEntry($site, BuildSource::Service, $service->id, 'Toilet Replacement', ['spoke_id' => $service->id]);
    manifestEntry($site, BuildSource::Standard, 'about', 'About');
    manifestEntry($site, BuildSource::Location, 'twn1', 'Clifton, NJ');

    // The STATED service behind the spoke — structure no longer fabricates it, so the page can only
    // pin a real one (services → structure is one-way; the projector links, never creates).
    Service::withoutGlobalScope(SiteScope::class)
        ->create(['site_id' => $site->id, 'name' => 'Toilet Replacement']);

    app(PageMaterializer::class)->materialize($site);

    $byTitle = pagesFor($site)->keyBy('title');

    // the service page carries its own service subject…
    $servicePage = $byTitle['Toilet Replacement'];
    expect($servicePage->primary_service_id)->not->toBeNull()
        ->and($servicePage->primaryService->name)->toBe('Toilet Replacement');

    // …while the category/hub page (spans the silo), the standard page, and the town page do not.
    expect($byTitle['Plumbing']->primary_service_id)->toBeNull()
        ->and($byTitle['About']->primary_service_id)->toBeNull()
        ->and($byTitle['Clifton, NJ']->primary_service_id)->toBeNull();
});

test('a standard page is stamped with its standard_type and gets its composer kit (composable only)', function () {
    (new WireframeKitSeeder)->run();
    $site = Site::factory()->create();
    manifestEntry($site, BuildSource::Standard, 'about', 'About');     // composer shipped
    manifestEntry($site, BuildSource::Standard, 'gallery', 'Gallery'); // not yet → held

    app(PageMaterializer::class)->materialize($site);

    $byTitle = pagesFor($site)->keyBy('title');

    expect($byTitle['About']->standard_type)->toBe(StandardPageType::About)
        ->and($byTitle['About']->wireframe_kit_id)->not->toBeNull()
        ->and($byTitle['About']->wireframeKit->name)->toBe('about-page');

    // Gallery still carries its identity, but has no kit → the surface holds it "Not ready yet".
    expect($byTitle['Gallery']->standard_type)->toBe(StandardPageType::Gallery)
        ->and($byTitle['Gallery']->wireframe_kit_id)->toBeNull();
});

test('colliding titles get deterministic disambiguated permalinks', function () {
    $site = Site::factory()->create();
    manifestEntry($site, BuildSource::Service, 'a', 'Springfield', ['priority' => 100]);
    manifestEntry($site, BuildSource::Service, 'b', 'Springfield', ['priority' => 101]);

    app(PageMaterializer::class)->materialize($site);

    expect(pagesFor($site)->pluck('slug')->sort()->values()->all())->toBe(['springfield', 'springfield-2']);
});

test('the URL map lists every page permalink after materialize, before drafting', function () {
    $site = Site::factory()->create();
    manifestEntry($site, BuildSource::Standard, 'home', 'Home');
    manifestEntry($site, BuildSource::Location, 'twn1', 'Austin, TX');

    app(PageMaterializer::class)->materialize($site);
    $map = app(Permalinks::class)->urlMap($site);

    expect($map)->toHaveCount(2)
        ->and(collect($map)->values()->all())->toContain('/home', '/austin-tx');
});

test('materialize carries the spoke primary_keyword onto the page as a resolved Keyword target', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $core = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => false,
        'name' => 'Drain Cleaning', 'primary_keyword' => 'drain cleaning newark', 'volume' => 480,
    ]);
    manifestEntry($site, BuildSource::Service, $core->id, 'Drain Cleaning', ['spoke_id' => $core->id]);

    app(PageMaterializer::class)->materialize($site);

    $page = pagesFor($site)->firstWhere('title', 'Drain Cleaning');
    expect($page->target_keyword_id)->not->toBeNull();

    $kw = Keyword::withoutGlobalScope(SiteScope::class)->find($page->target_keyword_id);
    expect($kw->query)->toBe('drain cleaning newark')
        ->and($kw->volume)->toBe(480)
        ->and($kw->target_content_id)->toBe($page->id); // bi-directional link → §5 sees it covered
});

test('materialize reuses an existing Keyword rather than duplicating (matched on normalized query)', function () {
    $site = Site::factory()->create();
    $blueprint = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    $core = Spoke::factory()->create([
        'site_id' => $site->id, 'silo_blueprint_id' => $blueprint->id, 'is_pillar' => false,
        'name' => 'Drain Cleaning', 'primary_keyword' => '  Drain Cleaning Newark ', // case/spacing differ
    ]);
    $existing = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'drain cleaning newark']);
    manifestEntry($site, BuildSource::Service, $core->id, 'Drain Cleaning', ['spoke_id' => $core->id]);

    app(PageMaterializer::class)->materialize($site);

    expect(Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1); // no dup
    expect(pagesFor($site)->firstWhere('title', 'Drain Cleaning')->target_keyword_id)->toBe($existing->id);
});
