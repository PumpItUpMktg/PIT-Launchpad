<?php

use App\Build\GuidedEntityProjector;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Market;
use App\Models\Site;
use App\Operator\Coverage\MarketArtifactRenamer;
use Illuminate\Support\Facades\Artisan;

function dirtyMarket(Site $s, string $name, ?string $geoId = null): Market
{
    return Market::factory()->create(['site_id' => $s->id, 'name' => $name, 'region' => 'MD', 'tier' => 'coverage', 'geo_id' => $geoId]);
}

function townPage(Site $s, Market $m, string $title, string $slug, ?int $wpPostId = null): Content
{
    return Content::factory()->create([
        'site_id' => $s->id, 'page_type' => PageType::Location, 'market_id' => $m->id,
        'title' => $title, 'slug' => $slug, 'wp_post_id' => $wpPostId,
    ]);
}

it('plans a rename for a dirty market, its CoverageArea, and its pinned town page (presence before absence)', function () {
    $site = Site::factory()->create();
    // CoverageArea stores clean (its write mutator strips the artifact); the market predates the mutator.
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    $market = dirtyMarket($site, '4, Marshall', '2451234');
    townPage($site, $market, '4, Marshall, MD', 'fallston/4-marshall-md');

    $plan = app(MarketArtifactRenamer::class)->plan($site);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['old'])->toBe('4, Marshall')
        ->and($plan[0]['new'])->toBe('Marshall')
        ->and($plan[0]['collision'])->toBeFalse()
        ->and($plan[0]['coverage_area_id'])->toBe($area->id)   // matched by geo_id
        ->and($plan[0]['pages'])->toHaveCount(1)
        ->and($plan[0]['pages'][0]['new_title'])->toBe('Marshall, MD');
});

it('leaves a clean market untouched', function () {
    $site = Site::factory()->create();
    dirtyMarket($site, 'Fallston', '2426000');

    expect(app(MarketArtifactRenamer::class)->plan($site))->toBe([]);
});

it('applies the cascade — market, CoverageArea, and page title all land on the same clean value', function () {
    $site = Site::factory()->create();
    // CoverageArea itself still dirty (predates the mutator) — force it past the write mutator for the fixture.
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    CoverageArea::withoutGlobalScopes()->whereKey($area->id)->update(['name' => '4, Marshall']); // simulate a legacy dirty row
    $market = dirtyMarket($site, '4, Marshall', '2451234');
    $page = townPage($site, $market, '4, Marshall, MD', 'fallston/4-marshall-md');

    $renamed = app(MarketArtifactRenamer::class)->apply($site);

    expect($renamed)->toBe(1)
        ->and(Market::withoutGlobalScopes()->find($market->id)->name)->toBe('Marshall')
        ->and(CoverageArea::withoutGlobalScopes()->find($area->id)->name)->toBe('Marshall')
        ->and(Content::withoutGlobalScopes()->find($page->id)->title)->toBe('Marshall, MD');
});

it('leaves the slug untouched — LocationNesting regenerates it from the corrected title on the next build', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    $market = dirtyMarket($site, '4, Marshall', '2451234');
    $page = townPage($site, $market, '4, Marshall, MD', 'fallston/4-marshall-md');

    app(MarketArtifactRenamer::class)->apply($site);

    expect(Content::withoutGlobalScopes()->find($page->id)->slug)->toBe('fallston/4-marshall-md'); // unchanged here
});

it('is a no-op for the next build — projectTerritories re-resolves the renamed market, no duplicate', function () {
    $site = Site::factory()->create();
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD', 'page_selected' => true]);
    $market = dirtyMarket($site, '4, Marshall', '2451234');
    townPage($site, $market, '4, Marshall, MD', 'fallston/4-marshall-md');

    app(MarketArtifactRenamer::class)->apply($site);
    $countAfterRename = Market::withoutGlobalScopes()->where('site_id', $site->id)->count();

    // The next build reads CoverageArea.name and firstOrCreate's the market by name. Because the rename
    // aligned Market.name to CoverageArea.name, it resolves the SAME market — no orphan, no duplicate.
    $resolved = app(GuidedEntityProjector::class)->marketForCoverageArea($area->id, $site);
    Market::withoutGlobalScopes()->firstOrCreate(
        ['site_id' => $site->id, 'name' => (string) CoverageArea::withoutGlobalScopes()->find($area->id)->name],
        ['region' => 'MD', 'tier' => 'coverage'],
    );

    expect($resolved?->id)->toBe($market->id)
        ->and(Market::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe($countAfterRename); // no mint
});

it('flags a name collision and does not apply it (leaves both markets for a manual merge)', function () {
    $site = Site::factory()->create();
    dirtyMarket($site, 'Marshall', '2451234');          // a clean market already owns the target name
    $dirty = dirtyMarket($site, '4, Marshall', '2451299'); // cleaning this would collide

    $plan = app(MarketArtifactRenamer::class)->plan($site);
    $renamed = app(MarketArtifactRenamer::class)->apply($site);

    expect(collect($plan)->firstWhere('market_id', $dirty->id)['collision'])->toBeTrue()
        ->and($renamed)->toBe(0)  // the colliding row is skipped
        ->and(Market::withoutGlobalScopes()->find($dirty->id)->name)->toBe('4, Marshall'); // untouched
});

it('flags a published page (its slug becomes a live-URL change on the next build)', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    $market = dirtyMarket($site, '4, Marshall', '2451234');
    townPage($site, $market, '4, Marshall, MD', 'fallston/4-marshall-md', wpPostId: 8821);

    $plan = app(MarketArtifactRenamer::class)->plan($site);

    expect($plan[0]['published_pages'])->toBe(1)
        ->and($plan[0]['pages'][0]['published'])->toBeTrue();
});

it('scopes the rename to one site — never touches another tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $ma = dirtyMarket($a, '4, Marshall', '2451234');
    $mb = dirtyMarket($b, '4, Marshall', '2451234');

    app(MarketArtifactRenamer::class)->apply($a);

    expect(Market::withoutGlobalScopes()->find($ma->id)->name)->toBe('Marshall')
        ->and(Market::withoutGlobalScopes()->find($mb->id)->name)->toBe('4, Marshall'); // untouched
});

it('is idempotent — a second apply renames nothing', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    dirtyMarket($site, '4, Marshall', '2451234');

    expect(app(MarketArtifactRenamer::class)->apply($site))->toBe(1)
        ->and(app(MarketArtifactRenamer::class)->plan($site))->toBe([])
        ->and(app(MarketArtifactRenamer::class)->apply($site))->toBe(0);
});

it('report-only by default writes nothing; --execute applies and verifies zero remaining', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    $market = dirtyMarket($site, '4, Marshall', '2451234');

    $code = Artisan::call('launchpad:rename-market-artifacts', ['--site' => $site->id]);
    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('would be renamed')
        ->and(Market::withoutGlobalScopes()->find($market->id)->name)->toBe('4, Marshall'); // unchanged

    $code = Artisan::call('launchpad:rename-market-artifacts', ['--site' => $site->id, '--execute' => true]);
    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Remaining renamable artifacts after re-read: 0')
        ->and(Market::withoutGlobalScopes()->find($market->id)->name)->toBe('Marshall'); // applied
});

it('reports a clean tenant as a real "nothing to rename" result', function () {
    $site = Site::factory()->create(['brand_name' => 'CleanCo']);
    dirtyMarket($site, 'Fallston', '2426000');

    $code = Artisan::call('launchpad:rename-market-artifacts', ['--site' => $site->id]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('No market artifacts found');
});
