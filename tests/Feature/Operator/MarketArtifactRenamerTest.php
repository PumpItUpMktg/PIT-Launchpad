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

it('plans a name rename for a dirty market + its CoverageArea (presence before absence)', function () {
    $site = Site::factory()->create();
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    $market = dirtyMarket($site, '4, Marshall', '2451234');

    $plan = app(MarketArtifactRenamer::class)->plan($site);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['old'])->toBe('4, Marshall')
        ->and($plan[0]['new'])->toBe('Marshall')
        ->and($plan[0]['collision'])->toBeFalse()
        ->and($plan[0]['coverage_area_id'])->toBe($area->id)   // matched by geo_id
        ->and($plan[0])->not->toHaveKey('pages');              // scope is markets.name — no page cascade
});

it('leaves a clean market untouched', function () {
    $site = Site::factory()->create();
    dirtyMarket($site, 'Fallston', '2426000');

    expect(app(MarketArtifactRenamer::class)->plan($site))->toBe([]);
});

it('applies the rename — market + CoverageArea land on the same clean value', function () {
    $site = Site::factory()->create();
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    CoverageArea::withoutGlobalScopes()->whereKey($area->id)->update(['name' => '4, Marshall']); // legacy dirty row
    $market = dirtyMarket($site, '4, Marshall', '2451234');

    $renamed = app(MarketArtifactRenamer::class)->apply($site);

    expect($renamed)->toBe(1)
        ->and(Market::withoutGlobalScopes()->find($market->id)->name)->toBe('Marshall')
        ->and(CoverageArea::withoutGlobalScopes()->find($area->id)->name)->toBe('Marshall');
});

it('NEVER touches a published page — pages are a dedup problem, not a retitle', function () {
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD']);
    $market = dirtyMarket($site, '4, Marshall', '2451234');
    $page = Content::factory()->create([
        'site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $market->id,
        'title' => '4, Marshall, MD', 'slug' => 'fallston/4-marshall-md', 'wp_post_id' => 8821,
    ]);

    app(MarketArtifactRenamer::class)->apply($site);

    $fresh = Content::withoutGlobalScopes()->find($page->id);
    expect($fresh->title)->toBe('4, Marshall, MD')      // title untouched (live indexed content)
        ->and($fresh->slug)->toBe('fallston/4-marshall-md');
});

it('is a no-op for the next build — projectTerritories re-resolves the renamed market, no duplicate', function () {
    $site = Site::factory()->create();
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Marshall', 'geo_id' => '2451234', 'state' => 'MD', 'page_selected' => true]);
    $market = dirtyMarket($site, '4, Marshall', '2451234');

    app(MarketArtifactRenamer::class)->apply($site);
    $countAfterRename = Market::withoutGlobalScopes()->where('site_id', $site->id)->count();

    $resolved = app(GuidedEntityProjector::class)->marketForCoverageArea($area->id, $site);
    Market::withoutGlobalScopes()->firstOrCreate(
        ['site_id' => $site->id, 'name' => (string) CoverageArea::withoutGlobalScopes()->find($area->id)->name],
        ['region' => 'MD', 'tier' => 'coverage'],
    );

    expect($resolved?->id)->toBe($market->id)
        ->and(Market::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe($countAfterRename); // no mint
});

it('flags a duplicate (cleaned name already a market) as a collision and skips it — merge, not rename', function () {
    $site = Site::factory()->create();
    dirtyMarket($site, 'Marshall', '2451234');          // the clean twin (a real duplicate underneath)
    $dirty = dirtyMarket($site, '4, Marshall', '2451299');

    $plan = app(MarketArtifactRenamer::class)->plan($site);
    $renamed = app(MarketArtifactRenamer::class)->apply($site);

    expect(collect($plan)->firstWhere('market_id', $dirty->id)['collision'])->toBeTrue()
        ->and($renamed)->toBe(0)
        ->and(Market::withoutGlobalScopes()->find($dirty->id)->name)->toBe('4, Marshall'); // untouched → merge tool
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
        ->and(Market::withoutGlobalScopes()->find($market->id)->name)->toBe('4, Marshall');

    $code = Artisan::call('launchpad:rename-market-artifacts', ['--site' => $site->id, '--execute' => true]);
    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Remaining renamable artifacts after re-read: 0')
        ->and(Market::withoutGlobalScopes()->find($market->id)->name)->toBe('Marshall');
});
