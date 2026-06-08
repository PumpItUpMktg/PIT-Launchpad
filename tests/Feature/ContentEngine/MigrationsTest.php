<?php

use App\Enums\RefreshTrigger;
use App\Models\Content;
use App\Models\RefreshEvent;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;

test('the §1 additions are present', function () {
    expect(Schema::hasTable('refresh_events'))->toBeTrue()
        ->and(Schema::hasColumns('contents', [
            'source_name', 'source_url', 'matched_silo_id', 'angle_hint', 'relevance_score', 'local_relevance',
        ]))->toBeTrue();
});

test('a refresh event records a content + trigger', function () {
    $site = Site::factory()->create();
    $content = Content::factory()->create(['site_id' => $site->id]);

    $event = RefreshEvent::factory()->create([
        'site_id' => $site->id,
        'content_id' => $content->id,
        'trigger' => RefreshTrigger::PositionDrop,
    ]);

    expect($event->trigger)->toBe(RefreshTrigger::PositionDrop)
        ->and($event->content->is($content))->toBeTrue();
});
