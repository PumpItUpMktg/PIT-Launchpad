<?php

use App\Client\CoverageSummary;
use App\Client\LeadsMetrics;
use App\Client\LocalGrid;
use App\Client\PerformanceCards;
use App\Client\PositionTrends;
use App\Client\QuickWins;
use App\Client\RankingGains;
use App\Enums\BeatabilityLane;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Conversion;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\RefreshEvent;
use App\Models\Silo;
use App\Models\Site;

test('leads metrics report totals and a weekly trend', function () {
    $site = Site::factory()->create();
    Conversion::factory()->create(['site_id' => $site->id, 'count' => 4, 'occurred_at' => now()]);
    Conversion::factory()->create(['site_id' => $site->id, 'count' => 2, 'occurred_at' => now()->subWeeks(2)]);

    $leads = app(LeadsMetrics::class);
    expect($leads->total($site))->toBe(6)
        ->and(array_sum($leads->trend($site)))->toBe(6);
});

test('ranking gains report improved and newly-ranked keywords', function () {
    $site = Site::factory()->create();
    $improved = Keyword::factory()->create(['site_id' => $site->id]);
    $new = Keyword::factory()->create(['site_id' => $site->id]);

    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $improved->id, 'lane' => BeatabilityLane::Organic, 'rank' => 20, 'captured_at' => now()->subMonth()]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $improved->id, 'lane' => BeatabilityLane::Organic, 'rank' => 5, 'captured_at' => now()]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $new->id, 'lane' => BeatabilityLane::Organic, 'rank' => null, 'captured_at' => now()->subMonth()]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $new->id, 'lane' => BeatabilityLane::Organic, 'rank' => 8, 'captured_at' => now()]);

    expect(app(RankingGains::class)->summary($site))->toBe(['improved' => 1, 'new' => 1]);
});

test('position trends carry the series, refresh markers and standings', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'target_content_id' => $content->id]);

    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 9, 'captured_at' => now()->subWeek()]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 4, 'captured_at' => now()]);
    RefreshEvent::factory()->create(['site_id' => $site->id, 'content_id' => $content->id]);

    $trend = app(PositionTrends::class)->forKeyword($keyword);

    expect($trend['series'])->toHaveCount(2)
        ->and($trend['refresh_markers'])->toHaveCount(1)
        ->and($trend['standings']['primary'])->toBe(4);
});

test('local grid returns latest local-pack rank per market', function () {
    $site = Site::factory()->create();
    $keyword = Keyword::factory()->create(['site_id' => $site->id]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Round Rock']);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'market_id' => $market->id, 'lane' => BeatabilityLane::LocalPack, 'rank' => 2, 'captured_at' => now()]);

    $grid = app(LocalGrid::class)->heatmap($site);
    expect($grid)->toHaveCount(1)
        ->and($grid[0]['market_name'])->toBe('Round Rock')
        ->and($grid[0]['rank'])->toBe(2);
});

test('coverage and quick-wins reflect published content and low-difficulty ranks', function () {
    $site = Site::factory()->create();
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing']);
    Content::factory()->count(2)->create(['site_id' => $site->id, 'silo_id' => $silo->id, 'status' => ContentStatus::Published]);

    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'difficulty' => 15, 'query' => 'tankless repair austin']);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 6, 'captured_at' => now()]);

    expect(app(CoverageSummary::class)->publishedCount($site))->toBe(2)
        ->and(app(CoverageSummary::class)->perSilo($site)[0]['silo_name'])->toBe('Plumbing')
        ->and(app(QuickWins::class)->landed($site))->toHaveCount(1)
        ->and(app(QuickWins::class)->landed($site)[0]['rank'])->toBe(6);
});

test('performance cards expose position and refresh history per published page', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'target_content_id' => $content->id]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $keyword->id, 'lane' => BeatabilityLane::Organic, 'rank' => 3, 'captured_at' => now()]);
    RefreshEvent::factory()->count(2)->create(['site_id' => $site->id, 'content_id' => $content->id]);

    $cards = app(PerformanceCards::class)->cards($site);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['best_rank'])->toBe(3)
        ->and($cards[0]['refresh_count'])->toBe(2);
});
