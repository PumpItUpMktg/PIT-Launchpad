<?php

use App\ContentEngine\Feeds\GeneratedFeedReconciler;
use App\Enums\FeedOrigin;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Source;

function reconciler(): GeneratedFeedReconciler
{
    return new GeneratedFeedReconciler('https://news.google.com', 'en-US', 'US', 'US:en');
}

function generatedFeeds(string $siteId)
{
    return Source::where('site_id', $siteId)->where('origin', FeedOrigin::Generated->value)->get();
}

it('materializes one generated feed per (routable keyword x market) with the market in the query', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'query' => 'water heater repair']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'no silo keyword']); // skipped — unroutable
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Dallas']);

    $result = reconciler()->reconcile($site);

    expect($result['upserted'])->toBe(2);
    $feeds = generatedFeeds($site->id);
    expect($feeds)->toHaveCount(2);

    $austin = $feeds->firstWhere('label', 'water heater repair · Austin (Google News)');
    expect($austin)->not->toBeNull()
        ->and($austin->silo_id)->toBe($silo->id)
        ->and($austin->enabled)->toBeTrue()
        ->and($austin->url)->toContain('news.google.com/rss/search')
        ->and(urldecode($austin->url))->toContain('water heater repair Austin');
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
