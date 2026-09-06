<?php

use App\Build\GuidedEntityProjector;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
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

it('soft-deletes an EMPTY duplicate town page rather than reassigning it into a self-inflicted duplicate', function () {
    $site = Site::factory()->create();
    $winner = mkt($site, 'Abingdon', '2402590048');
    $loser = mkt($site, '1, Abingdon', '2402590048');

    // Both markets hold a page for the SAME town (Abingdon). The survivor's is canonical (published, clean
    // title); the loser's is an EMPTY extra (candidate, dirty title) — a blind reassign would give the
    // survivor two Abingdon pages. It must be soft-deleted instead, keeping the survivor's page.
    $winnerPage = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $winner->id, 'title' => 'Abingdon, MD', 'slug' => 'abingdon-md', 'status' => 'published', 'wp_post_id' => 8821]);
    // The loser's Abingdon page is a genuine EMPTY extra: candidate, no slot payload (undrafted), never pushed.
    $loserPage = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $loser->id, 'title' => '1, Abingdon, MD', 'slug' => 'abingdon-md-2', 'status' => 'candidate', 'slot_payload' => [], 'wp_post_id' => null]);
    // A NON-colliding loser page (a different town) still reassigns to the survivor.
    $otherPage = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $loser->id, 'title' => 'Churchville, MD', 'slug' => 'churchville-md', 'status' => 'candidate', 'slot_payload' => []]);

    $plan = app(MarketMerger::class)->plan($site);
    expect($plan[0]['collision'])->toBeFalse()
        ->and($plan[0]['colliding_page_ids'])->toBe([$loserPage->id]);

    expect(app(MarketMerger::class)->apply($site))->toBe(1)
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($loserPage->id))->toBeNull()                       // dup soft-deleted (excluded)
        ->and(Content::withoutGlobalScope(SiteScope::class)->withTrashed()->find($loserPage->id)->trashed())->toBeTrue()
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($winnerPage->id)->market_id)->toBe($winner->id)     // survivor's page kept
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($otherPage->id)->market_id)->toBe($winner->id);     // other town reassigned

    // The survivor is left with exactly ONE (live) page for Abingdon — no self-inflicted duplicate.
    $abingdon = Content::withoutGlobalScope(SiteScope::class)->where('market_id', $winner->id)
        ->where('title', 'like', '%Abingdon%')->count();
    expect($abingdon)->toBe(1);
});

it('REFUSES the merge on a live-page collision and reports the index verdict of BOTH sides', function () {
    $site = Site::factory()->create();
    $winner = mkt($site, 'Abingdon', '2402590048');
    $loser = mkt($site, '1, Abingdon', '2402590048');

    // Both Abingdon pages are PUBLISHED — taking one down is a human decision, so the merge must refuse.
    $winnerPage = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $winner->id, 'title' => 'Abingdon, MD', 'slug' => 'abingdon-md', 'status' => 'published', 'wp_post_id' => 8821]);
    $loserPage = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $loser->id, 'title' => '1, Abingdon, MD', 'slug' => 'abingdon-md-2', 'status' => 'published', 'wp_post_id' => 9002]);

    // The LOSER's page is indexed; the SURVIVOR's is not. "Winner" was picked by name cleanliness, so the
    // report must surface both verdicts — discarding the loser here would lose ranking.
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $loserPage->id, 'url' => 'https://x/abingdon-md-2/', 'url_normalized' => '/abingdon-md-2', 'index_verdict' => 'PASS']);
    PageIndexState::create(['site_id' => $site->id, 'content_id' => $winnerPage->id, 'url' => 'https://x/abingdon-md/', 'url_normalized' => '/abingdon-md', 'index_verdict' => 'NEUTRAL']);

    $plan = app(MarketMerger::class)->plan($site);
    expect($plan[0]['collision'])->toBeTrue()
        ->and($plan[0]['hard_collisions'])->toHaveCount(1)
        ->and($plan[0]['hard_collisions'][0]['reason'])->toBe('published')
        ->and($plan[0]['hard_collisions'][0]['loser_index'])->toBe('indexed')
        ->and($plan[0]['hard_collisions'][0]['winner_index'])->toContain('not indexed');

    // Refused: nothing merged, both markets and both pages remain.
    expect(app(MarketMerger::class)->apply($site))->toBe(0)
        ->and(Market::withoutGlobalScopes()->find($loser->id))->not->toBeNull()
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($loserPage->id)->market_id)->toBe($loser->id);
});

it('never soft-deletes a page that was already pushed to WP — an undrafted row with a wp_post_id is a HARD collision', function () {
    $site = Site::factory()->create();
    $winner = mkt($site, 'Abingdon', '2402590048');
    $loser = mkt($site, '1, Abingdon', '2402590048');

    Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $winner->id, 'title' => 'Abingdon, MD', 'slug' => 'abingdon-md', 'status' => 'published', 'wp_post_id' => 8821]);
    // Undrafted (empty slot payload) and NOT "published" status — but it carries a wp_post_id from an earlier
    // push, so it has a LIVE URL. Soft-deleting it would orphan that URL, so it must be HARD, never soft.
    $loserPage = Content::factory()->create(['site_id' => $site->id, 'page_type' => PageType::Location, 'market_id' => $loser->id, 'title' => '1, Abingdon, MD', 'slug' => 'abingdon-md-2', 'status' => 'candidate', 'slot_payload' => [], 'wp_post_id' => 9100]);

    $plan = app(MarketMerger::class)->plan($site);
    expect($plan[0]['collision'])->toBeTrue()
        ->and($plan[0]['colliding_page_ids'])->toBe([])                                    // NOT queued for soft-delete
        ->and($plan[0]['hard_collisions'][0]['reason'])->toBe('pushed to WP (live URL)');

    expect(app(MarketMerger::class)->apply($site))->toBe(0)                                 // refused
        ->and(Content::withoutGlobalScope(SiteScope::class)->find($loserPage->id))->not->toBeNull(); // live page kept
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
