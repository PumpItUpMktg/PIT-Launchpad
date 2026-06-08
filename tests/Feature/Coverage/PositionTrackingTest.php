<?php

use App\Enums\BeatabilityLane;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\RefreshEvent;
use App\Models\Site;
use App\Operator\Coverage\PositionTracking;

function tracking(): PositionTracking
{
    return app(PositionTracking::class);
}

test('it reports the latest organic standing and per-market local standings', function () {
    $site = Site::factory()->create();
    $keyword = Keyword::factory()->create(['site_id' => $site->id]);
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin']);

    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id,
        'lane' => BeatabilityLane::Organic, 'rank' => 4, 'ranking_url' => 'https://apex.example/a', 'captured_at' => now(),
    ]);
    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id, 'market_id' => $market->id,
        'lane' => BeatabilityLane::LocalPack, 'rank' => 2, 'captured_at' => now(),
    ]);

    $standings = tracking()->forKeyword($keyword);

    expect($standings->organicRank)->toBe(4)
        ->and($standings->cannibalizing)->toBeFalse()
        ->and($standings->localByMarket)->toHaveCount(1)
        ->and($standings->localByMarket[0]['market_name'])->toBe('Austin')
        ->and($standings->localByMarket[0]['rank'])->toBe(2)
        ->and($standings->organicSeries)->toHaveCount(1);
});

test('multiple owned URLs in one capture flag cannibalization', function () {
    $site = Site::factory()->create();
    $keyword = Keyword::factory()->create(['site_id' => $site->id]);
    $captured = now()->subDay()->startOfDay();

    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id,
        'lane' => BeatabilityLane::Organic, 'rank' => 3, 'ranking_url' => 'https://apex.example/a', 'captured_at' => $captured,
    ]);
    PositionSnapshot::factory()->create([
        'site_id' => $site->id, 'keyword_id' => $keyword->id,
        'lane' => BeatabilityLane::Organic, 'rank' => 7, 'ranking_url' => 'https://apex.example/b', 'captured_at' => $captured,
    ]);

    expect(tracking()->forKeyword($keyword)->cannibalizing)->toBeTrue();
});

test('refresh-ROI counts the RefreshEvents on the keyword target content', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);
    $keyword = Keyword::factory()->create(['site_id' => $site->id, 'target_content_id' => $content->id]);

    RefreshEvent::factory()->count(2)->create(['site_id' => $site->id, 'content_id' => $content->id]);

    expect(tracking()->forKeyword($keyword)->refreshCount)->toBe(2);
});
