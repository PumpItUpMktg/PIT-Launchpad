<?php

use App\Build\PageMaterializer;
use App\Build\Permalinks;
use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;

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
