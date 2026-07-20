<?php

use App\ContentEngine\Feeds\BlogPopulator;
use App\Enums\FeedOrigin;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Silo;
use App\Models\Site;
use App\Models\Source;

test('with no keywords the chain is not ready and points the operator at discovery', function () {
    $site = Site::factory()->create();

    $report = app(BlogPopulator::class)->populate($site, ingest: false);

    expect($report->keywordsTotal)->toBe(0)
        ->and($report->ready())->toBeFalse()
        ->and($report->diagnosis())->toContain('Discover keywords');
});

test('an unassigned keyword that matches a silo rule_set is re-filed and builds a live feed', function () {
    $site = Site::factory()->create();
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'rule_set' => ['include_patterns' => ['sump pump']]]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Trooper']);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'sump pump repair cost']);

    $report = app(BlogPopulator::class)->populate($site, ingest: false);

    // Re-filed into the silo, a generated feed materialized, and the run reports itself as in-flight.
    expect($report->rebucketed)->toBe(1)
        ->and($report->keywordsSiloed)->toBe(1)
        ->and($report->ready())->toBeTrue()
        ->and($report->feedsActive)->toBeGreaterThanOrEqual(1)
        ->and($report->diagnosis())->toContain('Fetching news now');

    expect(Source::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $site->id)->where('origin', FeedOrigin::Generated->value)->where('enabled', true)->count())
        ->toBeGreaterThanOrEqual(1);
});

test('keywords that route to no silo leave the blog un-ready with a rule_set diagnosis', function () {
    $site = Site::factory()->create();
    // A silo whose rule_set matches nothing in the keyword, so the keyword stays unassigned.
    Silo::factory()->create(['site_id' => $site->id, 'name' => 'Drainage', 'rule_set' => ['include_patterns' => ['french drain']]]);
    Keyword::factory()->create(['site_id' => $site->id, 'silo_id' => null, 'query' => 'sump pump repair']);

    $report = app(BlogPopulator::class)->populate($site, ingest: false);

    expect($report->keywordsTotal)->toBe(1)
        ->and($report->keywordsSiloed)->toBe(0)
        ->and($report->ready())->toBeFalse()
        ->and($report->diagnosis())->toContain('routed to a silo');
});
