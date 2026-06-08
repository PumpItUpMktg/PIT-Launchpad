<?php

use App\Enums\BeatabilityLane;
use App\Integrations\LocalGrid\GridMetrics;
use App\Integrations\Serp\SerpResult;
use App\Integrations\Serp\SerpResultSet;
use App\KeywordGenerator\Tracking\CannibalizationDetector;
use App\KeywordGenerator\Tracking\PositionTracker;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Site;

test('the tracker stores an organic series and a per-market local series', function () {
    $site = Site::factory()->create();
    $keyword = Keyword::factory()->create(['site_id' => $site->id]);
    $market = Market::factory()->priority()->create(['site_id' => $site->id]);

    $tracker = new PositionTracker;

    $organic = $tracker->recordOrganic($keyword, rank: 7, rankingUrl: 'https://site.com/page');
    $local = $tracker->recordLocalGrid($keyword, $market, new GridMetrics('q', 4.5, 0.6, 0.9));

    expect($organic->lane)->toBe(BeatabilityLane::Organic)
        ->and($organic->rank)->toBe(7)
        ->and($organic->market_id)->toBeNull()
        ->and($local->lane)->toBe(BeatabilityLane::LocalPack)
        ->and($local->market_id)->toBe($market->id)
        ->and((float) $local->avg_rank)->toBe(4.5)
        ->and(PositionSnapshot::withoutGlobalScopes()->count())->toBe(2);
});

test('cannibalization fires when two of our URLs rank for one keyword', function () {
    $detector = new CannibalizationDetector;

    $cannibalizing = new SerpResultSet('water heater repair', [
        new SerpResult(3, 'https://apex.example/services/water-heater', 'apex.example'),
        new SerpResult(5, 'https://apex.example/blog/water-heater-tips', 'apex.example'),
        new SerpResult(7, 'https://rival.com/wh', 'rival.com'),
    ]);
    $clean = new SerpResultSet('drain cleaning', [
        new SerpResult(2, 'https://apex.example/services/drain', 'apex.example'),
        new SerpResult(4, 'https://rival.com/drain', 'rival.com'),
    ]);

    expect($detector->isCannibalizing($cannibalizing, 'https://apex.example'))->toBeTrue()
        ->and($detector->offendingUrls($cannibalizing, 'apex.example'))->toHaveCount(2)
        ->and($detector->isCannibalizing($clean, 'apex.example'))->toBeFalse();
});
