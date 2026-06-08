<?php

use App\Models\Content;
use App\Models\Keyword;
use App\Models\Site;
use App\Operator\Coverage\TargetQueue;

function targetQueue(): TargetQueue
{
    return app(TargetQueue::class);
}

test('gaps lists only uncovered keywords, highest opportunity first', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);

    Keyword::factory()->create(['site_id' => $site->id, 'opportunity_score' => 0.95, 'target_content_id' => $content->id]);
    $highGap = Keyword::factory()->create(['site_id' => $site->id, 'opportunity_score' => 0.80, 'target_content_id' => null]);
    $lowGap = Keyword::factory()->create(['site_id' => $site->id, 'opportunity_score' => 0.30, 'target_content_id' => null]);

    $gaps = targetQueue()->gaps($site->id);

    expect($gaps->pluck('id')->all())->toBe([$highGap->id, $lowGap->id]);
});

test('the queue orders by operator priority, then opportunity', function () {
    $site = Site::factory()->create();
    $promoted = Keyword::factory()->create(['site_id' => $site->id, 'priority' => 5, 'opportunity_score' => 0.2]);
    $highOpp = Keyword::factory()->create(['site_id' => $site->id, 'priority' => 0, 'opportunity_score' => 0.9]);

    $queue = targetQueue()->queue($site->id);

    expect($queue->first()->id)->toBe($promoted->id)
        ->and($queue->last()->id)->toBe($highOpp->id);
});

test('promote and demote adjust the operator priority', function () {
    $keyword = Keyword::factory()->create(['priority' => 0]);

    targetQueue()->promote($keyword);
    expect($keyword->fresh()->priority)->toBe(1);

    targetQueue()->demote($keyword);
    targetQueue()->demote($keyword);
    expect($keyword->fresh()->priority)->toBe(-1);
});
