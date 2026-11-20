<?php

use App\ContentEngine\Feeds\GeneratedFeedReconciler;
use App\Enums\FeedOrigin;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Market;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Database\QueryException;

function reconciler(): GeneratedFeedReconciler
{
    return new GeneratedFeedReconciler('https://news.google.com', 'en-US', 'US', 'US:en');
}

// The market shape is the legacy one — these tests pin it; the state-shape tests below set the default back.
beforeEach(fn () => config(['launchpad.feeds.generated.scope' => 'markets']));

function generatedFeeds(string $siteId)
{
    return Source::where('site_id', $siteId)->where('origin', FeedOrigin::Generated->value)->get();
}

it('materializes one generated feed per (routable keyword x market) with the market in the query', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'water heater repair']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'no silo keyword']); // skipped — unroutable
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'region' => 'TX']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Dallas', 'region' => 'TX']);

    $result = reconciler()->reconcile($site);

    expect($result['upserted'])->toBe(2);
    $feeds = generatedFeeds($site->id);
    expect($feeds)->toHaveCount(2);

    // Market string is city + state abbrev ("Austin TX").
    $austin = $feeds->firstWhere('label', 'water heater repair · Austin TX (Google News)');
    expect($austin)->not->toBeNull()
        ->and($austin->silo_id)->toBe($silo->id)
        ->and($austin->enabled)->toBeTrue()
        ->and($austin->url)->toContain('news.google.com/rss/search')
        ->and(urldecode($austin->url))->toContain('water heater repair Austin TX');
});

it('is idempotent — re-running does not duplicate feeds', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'drain cleaning']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);

    reconciler()->reconcile($site);
    reconciler()->reconcile($site);

    expect(generatedFeeds($site->id))->toHaveCount(1);
});

it('deactivates a retired (keyword, market) pair instead of deleting it', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);

    reconciler()->reconcile($site);
    expect(generatedFeeds($site->id)->first()->enabled)->toBeTrue();

    $keyword->delete(); // the source pair is gone
    $result = reconciler()->reconcile($site);

    expect($result['deactivated'])->toBe(1);
    $feeds = generatedFeeds($site->id);
    expect($feeds)->toHaveCount(1)                       // row preserved — provenance survives
        ->and($feeds->first()->enabled)->toBeFalse();   // but deactivated
});

it('reactivates a feed when its pair returns', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'gas line']);

    reconciler()->reconcile($site);
    Source::where('site_id', $site->id)->update(['enabled' => false]);

    reconciler()->reconcile($site);

    expect(generatedFeeds($site->id)->first()->enabled)->toBeTrue();
});

it('makes one national feed per keyword when the site has no markets', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'water heater repair']);

    reconciler()->reconcile($site);

    $feed = generatedFeeds($site->id)->first();
    expect($feed)->not->toBeNull()
        ->and($feed->derived_from)->toEndWith(':mkt:national')
        ->and(urldecode($feed->url))->toContain('q=water heater repair');
});

it('skips a held (on_hold) market and retires nothing else', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'drain cleaning']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'region' => 'TX', 'on_hold' => true]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Dallas', 'region' => 'TX']);

    $result = reconciler()->reconcile($site);

    expect($result['held_markets_skipped'])->toBe(1)
        ->and($result['upserted'])->toBe(1);
    $live = generatedFeeds($site->id)->where('enabled', true);
    expect($live)->toHaveCount(1)
        ->and($live->first()->label)->toContain('Dallas');
});

it('does NOT key off a publish-held location — only the market\'s own on_hold (no Market↔Location name match)', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump repair']);
    // A market that name-matches a publish-held Location, but whose OWN on_hold is false → NOT held.
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Fallston', 'region' => 'MD', 'on_hold' => false]);
    Location::factory()->create([
        'site_id' => $site->id,
        'publish_held' => true,
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Fallston'],
            ['types' => ['administrative_area_level_1'], 'short_name' => 'MD'],
        ],
    ]);

    $result = reconciler()->reconcile($site);

    // The feed IS generated — a held Location does not suppress it (recorded gap; no fragile name match).
    expect($result['held_markets_skipped'])->toBe(0)
        ->and(generatedFeeds($site->id)->where('enabled', true))->toHaveCount(1);
});

it('collapses two signatures that resolve to the same search into one enabled feed', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    // Two distinct keyword rows, identical query → identical Google-News URL per market.
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'drain cleaning']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'drain cleaning']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'region' => 'TX']);

    $result = reconciler()->reconcile($site);

    expect($result['url_duplicates_skipped'])->toBe(1)
        ->and($result['upserted'])->toBe(1)
        ->and(generatedFeeds($site->id)->where('enabled', true))->toHaveCount(1);
});

it('the partial unique index rejects a second ENABLED feed at the same URL, but allows a disabled one', function () {
    $site = Site::factory()->create();
    $url = 'https://news.google.com/rss/search?q=drain+cleaning+austin';
    Source::factory()->create(['site_id' => $site->id, 'url' => $url, 'enabled' => true]);

    expect(fn () => Source::factory()->create(['site_id' => $site->id, 'url' => $url, 'enabled' => true]))
        ->toThrow(QueryException::class);

    // A DISABLED duplicate is fine — history/provenance is kept, only one may be live.
    $disabled = Source::factory()->create(['site_id' => $site->id, 'url' => $url, 'enabled' => false]);
    expect($disabled->exists)->toBeTrue();
});

it('the default state shape makes ONE feed per keyword, the search scoped to the footprint states by full name', function () {
    config(['launchpad.feeds.generated.scope' => 'states']);
    $site = Site::factory()->create(['corporate_state' => 'NJ']);
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    $sumpKeyword = Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'french drain']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'region' => 'PA']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Fallston', 'region' => 'MD']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Montclair', 'region' => 'NJ']);

    $result = reconciler()->reconcile($site);

    expect($result['upserted'])->toBe(2)->and($result['scope'])->toBe('states');
    $feeds = generatedFeeds($site->id);
    expect($feeds)->toHaveCount(2);
    $sump = $feeds->firstWhere('label', 'sump pump · MD, NJ, PA (Google News)');
    expect($sump)->not->toBeNull()
        ->and($sump->silo_id)->toBe($silo->id)
        ->and($sump->derived_from)->toBe('kw:'.$sumpKeyword->id.':states:MD-NJ-PA')
        ->and(urldecode($sump->url))->toContain('q=sump pump (Maryland OR "New Jersey" OR Pennsylvania)');

    // Switching shapes retires the other shape's feeds (deactivation, never deletion) and re-running is idempotent.
    config(['launchpad.feeds.generated.scope' => 'markets']);
    $legacy = reconciler()->reconcile($site);
    expect($legacy['scope'])->toBe('markets')->and($legacy['upserted'])->toBe(6)->and($legacy['deactivated'])->toBe(2);
    expect(generatedFeeds($site->id)->where('enabled', true))->toHaveCount(6)
        ->and(generatedFeeds($site->id)->where('enabled', false))->toHaveCount(2);
    config(['launchpad.feeds.generated.scope' => 'states']);
    reconciler()->reconcile($site);
    reconciler()->reconcile($site);
    expect(generatedFeeds($site->id)->where('enabled', true))->toHaveCount(2)->and(generatedFeeds($site->id))->toHaveCount(8);
});

it('the state shape falls back to the market shape when the footprint is unknown, and still makes nothing when every market is held', function () {
    config(['launchpad.feeds.generated.scope' => 'states']);
    $site = Site::factory()->create(['corporate_state' => null]);
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'sump pump']);

    // No geography at all → national, as before.
    $result = reconciler()->reconcile($site);
    expect($result['scope'])->toBe('markets')->and(generatedFeeds($site->id)->first()->derived_from)->toEndWith(':mkt:national');

    // Every market held → nothing, as before (the held market's state must not quietly become a state feed).
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'region' => 'PA', 'on_hold' => true]);
    $held = reconciler()->reconcile($site);
    expect($held['upserted'])->toBe(0)->and($held['held_markets_skipped'])->toBe(1)
        ->and(generatedFeeds($site->id)->where('enabled', true))->toHaveCount(0);
});

it('builds the state query with full state names, quoting the two-word ones', function () {
    expect(GeneratedFeedReconciler::query('sump pump', null, ['MD', 'NJ', 'PA']))->toBe('sump pump (Maryland OR "New Jersey" OR Pennsylvania)')
        ->and(GeneratedFeedReconciler::query('sump pump', 'Austin TX'))->toBe('sump pump Austin TX')
        ->and(GeneratedFeedReconciler::query('sump pump', null))->toBe('sump pump');
});
