<?php

use App\ContentEngine\Questions\RefreshMarks;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Site;
use Carbon\Carbon;

it('reads the latest mark, prefers the republish for "since", and stamps republished_at only for a refresh not yet republished', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'meta' => [
        'question_refresh' => ['query' => 'q', 'refreshed_at' => '2026-11-07T10:00:00+00:00'],
        'news_refresh' => ['candidate_title' => 'n', 'refreshed_at' => '2026-11-12T09:00:00+00:00'],
    ]]);

    expect(RefreshMarks::latest($post)['key'])->toBe('news_refresh')
        ->and(RefreshMarks::since($post)->toIso8601String())->toBe('2026-11-12T09:00:00+00:00');

    // The push stamps both un-republished marks.
    expect(RefreshMarks::stampRepublished($post, Carbon::parse('2026-11-12T15:00:00+00:00')))->toBeTrue();
    $post->refresh();
    expect($post->meta['question_refresh']['republished_at'])->toBe('2026-11-12T15:00:00+00:00')
        ->and($post->meta['news_refresh']['republished_at'])->toBe('2026-11-12T15:00:00+00:00')
        ->and(RefreshMarks::since($post)->toIso8601String())->toBe('2026-11-12T15:00:00+00:00');

    // A plain re-push later changes nothing; a NEW refresh after the stamp is stamped again on its publish.
    expect(RefreshMarks::stampRepublished($post, Carbon::parse('2026-11-20T08:00:00+00:00')))->toBeFalse();
    $meta = $post->meta;
    $meta['question_refresh']['refreshed_at'] = '2026-12-07T10:00:00+00:00';
    $post->forceFill(['meta' => $meta])->save();
    expect(RefreshMarks::stampRepublished($post, Carbon::parse('2026-12-07T16:00:00+00:00')))->toBeTrue();
    $post->refresh();
    expect($post->meta['question_refresh']['republished_at'])->toBe('2026-12-07T16:00:00+00:00')
        ->and($post->meta['news_refresh']['republished_at'])->toBe('2026-11-12T15:00:00+00:00');

    $plain = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'meta' => []]);
    expect(RefreshMarks::latest($plain))->toBeNull()->and(RefreshMarks::since($plain))->toBeNull()->and(RefreshMarks::stampRepublished($plain))->toBeFalse();
});
