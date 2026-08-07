<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Filament\Console\Pages\Published;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Site;
use App\Models\User;
use App\OpsConsole\PublishedContentBoard;
use Illuminate\Support\Facades\Queue;

it('lists live blog posts and site pages in separate sections', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 111,
        'title' => 'Live post', 'slug' => 'live-post',
    ]);
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 222, 'title' => 'Live page', 'slug' => 'live-page',
    ]);
    // A draft must NOT appear.
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Draft']);

    $board = app(PublishedContentBoard::class)->forSite($site->id);

    expect(collect($board['posts'])->pluck('id')->all())->toBe([$post->id])
        ->and(collect($board['pages'])->pluck('id')->all())->toBe([$page->id])
        ->and($board['posts'][0]['url'])->toBe('https://apex.example/live-post/');
});

it('re-syncs a live item to WordPress but skips one never pushed', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();

    $live = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 111, 'title' => 'Live']);
    $neverPushed = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => null, 'title' => 'No wp id']);

    $page = new Published;
    $page->siteId = $site->id;

    $page->repush($live->id);
    $page->repush($neverPushed->id); // no wp_post_id -> no-op

    Queue::assertPushed(PublishContent::class, 1);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job): bool => $job->contentId === $live->id);
});
