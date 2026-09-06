<?php

use App\Build\GuidedEntityProjector;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Service;
use App\Models\Site;
use App\Operator\Coverage\MarketMerger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function mkt(Site $s, string $name, string $geoId): Market
{
    return Market::factory()->create(['site_id' => $s->id, 'name' => $name, 'region' => 'MD', 'tier' => 'coverage', 'geo_id' => $geoId]);
}

it('plans a merge for a duplicate (numbered) market sharing a geo_id with a clean twin', function () {
    $site = Site::factory()->create();
    $winner = mkt($site, 'Abingdon', '2402590048');
    $loser = mkt($site, '1, Abingdon', '2402590048'); // same Census place → duplicate

    $plan = app(MarketMerger::class)->plan($site);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['ambiguous'])->toBeFalse()
        ->and($plan[0]['winner_id'])->toBe($winner->id)
        ->and($plan[0]['loser_id'])->toBe($loser->id)
        ->and($plan[0]['geo_id'])->toBe('2402590048');
});

it('does not surface a market with a unique geo_id (no twin, not a duplicate)', function () {
    $site = Site::factory()->create();
    mkt($site, '2, Halls Cross Roads', '2451111'); // sole market for this place — rename, not merge

    expect(app(MarketMerger::class)->plan($site))->toBe([]);
});

it('flags an ambiguous group (no single clean survivor) and never auto-merges it', function () {
    $site = Site::factory()->create();
    mkt($site, '1, Twin', '2409999');
    mkt($site, '2, Twin', '2409999'); // two dirties, no clean → ambiguous

    $plan = app(MarketMerger::class)->plan($site);

    expect($plan)->toHaveCount(1)
        ->and($plan[0]['ambiguous'])->toBeTrue()
        ->and(app(MarketMerger::class)->apply($site))->toBe(0); // left for a human
});

it('reassigns every dependent to the survivor, then deletes the duplicate', function () {
    $site = Site::factory()->create();
    $winner = mkt($site, 'Abingdon', '2402590048');
    $loser = mkt($site, '1, Abingdon', '2402590048');

    $kw = Keyword::withoutGlobalScopes()->forceCreate(['site_id' => $site->id, 'market_id' => $loser->id, 'query' => 'x', 'source' => 'seed', 'status' => 'scored']);
    $page = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $loser->id, 'title' => '1, Abingdon, MD', 'slug' => 'abingdon-md-2']);
    $service = Service::factory()->create(['site_id' => $site->id]);
    $loser->services()->attach($service->id);

    $merged = app(MarketMerger::class)->apply($site);

    expect($merged)->toBe(1)
        ->and(Market::withoutGlobalScopes()->find($loser->id))->toBeNull()               // duplicate gone
        ->and(Keyword::withoutGlobalScopes()->find($kw->id)->market_id)->toBe($winner->id) // keyword moved
        ->and(Content::withoutGlobalScopes()->find($page->id)->market_id)->toBe($winner->id) // page moved
        ->and(DB::table('market_service')->where('market_id', $winner->id)->where('service_id', $service->id)->exists())->toBeTrue(); // pivot inherited
});

it('cleans the place\'s one CoverageArea name so the next build cannot re-mint the duplicate', function () {
    $site = Site::factory()->create();
    mkt($site, 'Abingdon', '2402590048');
    mkt($site, '1, Abingdon', '2402590048');
    // There is exactly ONE CoverageArea per (site, geo_id) — a unique index. Here it is still legacy-dirty:
    // if left as "1, Abingdon", the next build's projectTerritories firstOrCreate's THAT and re-mints the loser.
    $area = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Abingdon', 'geo_id' => '2402590048', 'state' => 'MD', 'page_selected' => true]);
    CoverageArea::withoutGlobalScopes()->whereKey($area->id)->update(['name' => '1, Abingdon']); // legacy dirty row

    app(MarketMerger::class)->apply($site);

    // The area is cleaned in place (never deleted — it is the town's only CoverageArea).
    expect((string) CoverageArea::withoutGlobalScopes()->find($area->id)->getAttribute('name'))->toBe('Abingdon');

    // The next build's territory projection reads the now-clean area → resolves the survivor, mints nothing.
    app(GuidedEntityProjector::class)->project($site);
    expect(Market::withoutGlobalScopes()->where('site_id', $site->id)->where('geo_id', '2402590048')->count())->toBe(1);
});

it('scopes the merge to one site — never touches another tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    mkt($a, 'Abingdon', '2402590048');
    $la = mkt($a, '1, Abingdon', '2402590048');
    mkt($b, 'Abingdon', '2402590048');
    $lb = mkt($b, '1, Abingdon', '2402590048');

    app(MarketMerger::class)->apply($a);

    expect(Market::withoutGlobalScopes()->find($la->id))->toBeNull()          // A merged
        ->and(Market::withoutGlobalScopes()->find($lb->id))->not->toBeNull(); // B untouched
});

it('report-only by default writes nothing; --execute applies', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    mkt($site, 'Abingdon', '2402590048');
    $loser = mkt($site, '1, Abingdon', '2402590048');

    $code = Artisan::call('launchpad:merge-markets', ['--site' => $site->id]);
    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('would be merged')
        ->and(Market::withoutGlobalScopes()->find($loser->id))->not->toBeNull(); // unchanged

    $code = Artisan::call('launchpad:merge-markets', ['--site' => $site->id, '--execute' => true]);
    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('Remaining mergeable duplicates after re-read: 0')
        ->and(Market::withoutGlobalScopes()->find($loser->id))->toBeNull(); // merged + deleted
});
