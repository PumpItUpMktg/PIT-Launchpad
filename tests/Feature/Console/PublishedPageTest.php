<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Filament\Console\Pages\PublishedBlog;
use App\Jobs\PublishContent;
use App\Models\Content;
use App\Models\Location;
use App\Models\Site;
use App\Models\User;
use App\OpsConsole\PublishedContentBoard;
use Illuminate\Support\Facades\Queue;

it('buckets live content into blog / core / service / storefront tabs', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 111,
        'title' => 'Live post', 'slug' => 'live-post',
    ]);
    $service = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'status' => ContentStatus::Published, 'wp_post_id' => 222, 'title' => 'Live service', 'slug' => 'live-service',
    ]);
    $home = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Home,
        'status' => ContentStatus::Published, 'wp_post_id' => 333, 'title' => 'Home', 'slug' => 'home',
    ]);
    // A draft must NOT appear.
    Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::NeedsReview, 'title' => 'Draft']);

    $board = app(PublishedContentBoard::class)->forSite($site->id);

    expect(collect($board['blog'])->pluck('id')->all())->toBe([$post->id])
        ->and(collect($board['service'])->pluck('id')->all())->toBe([$service->id])
        ->and(collect($board['core'])->pluck('id')->all())->toBe([$home->id])
        ->and($board['blog'][0]['url'])->toBe('https://apex.example/live-post/');
});

it('groups location pages under their storefront: hub page vs town pages', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $storefront = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Clifton Office', 'is_storefront' => true,
    ]);

    // The base-location hub page (location_id pinned) → the Storefront-Pages tab.
    $hub = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'wp_post_id' => 10, 'title' => 'Clifton', 'slug' => 'clifton',
        'location_id' => $storefront->id,
    ]);
    // A town page nested under it (parent_location_id pinned) → the Location-Pages tab.
    $town = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published, 'wp_post_id' => 11, 'title' => 'Nutley', 'slug' => 'clifton/nutley',
        'parent_location_id' => $storefront->id,
    ]);

    $board = app(PublishedContentBoard::class)->forSite($site->id);

    expect($board['storefronts'])->toHaveCount(1);
    $sf = $board['storefronts'][0];
    expect($sf['name'])->toBe('Clifton Office')
        ->and($sf['is_storefront'])->toBeTrue()
        ->and($sf['hub']['id'])->toBe($hub->id)
        ->and(collect($sf['towns'])->pluck('id')->all())->toBe([$town->id]);
});

it('re-syncs a live item to WordPress but skips one never pushed', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();

    $live = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => 111, 'title' => 'Live']);
    $neverPushed = Content::factory()->post()->create(['site_id' => $site->id, 'status' => ContentStatus::Published, 'wp_post_id' => null, 'title' => 'No wp id']);

    $page = new PublishedBlog;
    $page->siteId = $site->id;

    $page->repush($live->id);
    $page->repush($neverPushed->id); // no wp_post_id -> no-op

    Queue::assertPushed(PublishContent::class, 1);
    Queue::assertPushed(PublishContent::class, fn (PublishContent $job): bool => $job->contentId === $live->id);
});
