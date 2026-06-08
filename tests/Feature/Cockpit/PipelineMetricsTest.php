<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Silo;
use App\Models\Site;
use App\Operator\PipelineMetrics;

function metrics(): PipelineMetrics
{
    return app(PipelineMetrics::class);
}

test('stat cards count the headline statuses, portfolio and per-tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    Content::factory()->count(2)->create(['site_id' => $a->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $a->id, 'status' => ContentStatus::Approved]);
    Content::factory()->create(['site_id' => $a->id, 'status' => ContentStatus::RenderFailed]);
    Content::factory()->create(['site_id' => $b->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $a->id, 'status' => ContentStatus::Published, 'published_at' => now()]);

    $portfolio = metrics()->statCards();
    expect($portfolio['needs_review'])->toBe(3)
        ->and($portfolio['approved_pending'])->toBe(1)
        ->and($portfolio['render_failed'])->toBe(1)
        ->and($portfolio['published_this_week'])->toBe(1);

    $tenant = metrics()->statCards($a->id);
    expect($tenant['needs_review'])->toBe(2);
});

test('the funnel maps statuses onto pipeline stages', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Candidate]);
    Content::factory()->count(2)->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::InReview]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published]);

    $funnel = metrics()->funnel($site->id);

    expect($funnel['candidate'])->toBe(1)
        ->and($funnel['in_review'])->toBe(3) // needs_review (2) + in_review (1)
        ->and($funnel['published'])->toBe(1);
});

test('per-silo counts content volume by silo, highest first', function () {
    $site = Site::factory()->create();
    $big = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Plumbing']);
    $small = Silo::factory()->create(['site_id' => $site->id, 'name' => 'HVAC']);

    Content::factory()->count(3)->create(['site_id' => $site->id, 'silo_id' => $big->id]);
    Content::factory()->create(['site_id' => $site->id, 'silo_id' => $small->id]);

    $rows = metrics()->perSilo($site->id);

    expect($rows[0]['silo_name'])->toBe('Plumbing')
        ->and($rows[0]['total'])->toBe(3)
        ->and($rows[1]['total'])->toBe(1);
});

test('published-per-week zero-fills the window and buckets by week', function () {
    $site = Site::factory()->create();
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()]);
    Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'published_at' => now()->subWeeks(2)]);

    $weeks = metrics()->publishedPerWeek($site->id, 8);

    expect($weeks)->toHaveCount(8)
        ->and(array_sum($weeks))->toBe(2)
        ->and(end($weeks))->toBe(1); // the current week
});

test('job health counts render and publish outcomes with failed-content links', function () {
    $site = Site::factory()->create();
    RenderJob::factory()->rendered()->create(['site_id' => $site->id]);
    RenderJob::factory()->failed()->create(['site_id' => $site->id]);
    $failed = Content::factory()->create(['site_id' => $site->id, 'status' => ContentStatus::PublishFailed]);

    $health = metrics()->jobHealth($site->id);

    expect($health['render_succeeded'])->toBe(1)
        ->and($health['render_failed'])->toBe(1)
        ->and($health['publish_failed'])->toBe(1)
        ->and($health['failed_content'])->toHaveCount(1)
        ->and($health['failed_content'][0]['id'])->toBe($failed->id);
});
