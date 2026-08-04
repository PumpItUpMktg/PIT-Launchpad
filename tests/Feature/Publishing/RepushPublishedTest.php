<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\RepushPublished;
use Illuminate\Support\Facades\Queue;

function rpPost(Site $site, string $status = 'published', ?int $wpPostId = 5): Content
{
    return Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post->value, 'status' => $status,
        'wp_post_id' => $wpPostId, 'title' => 'Post', 'slug' => 'post-'.uniqid(),
    ]);
}

it('re-pushes only published posts with a wp_post_id, staggered in waves', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);

    foreach (range(1, 3) as $n) {
        rpPost($site, 'published', $n);
    }
    rpPost($site, 'needs_review', null);       // not published → excluded
    rpPost($site, 'published', null);           // published but never pushed → excluded
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page->value, 'status' => ContentStatus::Published->value, 'wp_post_id' => 9, 'slug' => 'a-page']); // page → excluded for kind=Post

    $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Post], chunk: 2, intervalSeconds: 15);

    expect($result['count'])->toBe(3)
        ->and($result['waves'])->toBe(2);       // 3 posts / chunk 2 = 2 waves

    Queue::assertPushed(PublishContent::class, 3);
});

it('dry-run counts without dispatching', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    rpPost($site);
    rpPost($site);

    $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Post], dryRun: true);

    expect($result['count'])->toBe(2);
    Queue::assertNothingPushed();
});

it('re-pushes pages too when both kinds are requested', function () {
    Queue::fake();
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    rpPost($site);
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page->value, 'status' => ContentStatus::Published->value, 'wp_post_id' => 9, 'slug' => 'a-page']);

    app(RepushPublished::class)->dispatch($site, [ContentKind::Post, ContentKind::Page]);

    Queue::assertPushed(PublishContent::class, 2);
});
