<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Enums\RenderStatus;
use App\Models\Content;
use App\Models\RenderJob;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;

test('blog-status reports the placeholder category, its description, and a failed hero render', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);

    $pillar = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Hub,
        'meta' => ['seo' => ['meta_description' => 'All sump pump work explained.']],
    ]);
    $silo = Silo::factory()->create(['site_id' => $site->id, 'name' => 'Sump Pumps', 'pillar_content_id' => $pillar->id, 'wp_category_id' => null]);

    $post = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'matched_silo_id' => $silo->id, 'slug' => 'sump-pump-guide', 'title' => 'Sump Pump Guide', 'wp_post_id' => 42,
        'meta' => ['image_specs' => [['slot' => 'hero_image', 'prompt' => 'x', 'seo_filename' => 'x.webp', 'alt' => 'x']]],
    ]);
    RenderJob::factory()->create([
        'site_id' => $site->id, 'content_id' => $post->id, 'slot' => 'hero_image',
        'status' => RenderStatus::RenderFailed, 'attempts' => 3, 'error' => 'fal 401 unauthorized',
    ]);

    $code = Artisan::call('launchpad:blog-status', ['site' => $site->id]);
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('sump-pump-guide')
        ->and($out)->toContain('NOT mapped in WP')                 // the "Silo …" placeholder
        ->and($out)->toContain('All sump pump work explained.')    // category description from the pillar
        ->and($out)->toContain('RENDER FAILED')
        ->and($out)->toContain('fal 401 unauthorized');            // the actual render error
});

test('blog-status surfaces posts stuck at "queued to publish" and points at the drain command', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    // Two posts approved (dispatched but never rendered) = the stalled-worker symptom.
    Content::factory()->count(2)->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Approved,
    ]);
    // One published post so the report body renders too.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'slug' => 'live-one', 'title' => 'Live One', 'wp_post_id' => 9, 'meta' => ['image_specs' => []],
    ]);

    Artisan::call('launchpad:blog-status', ['site' => $site->id]);
    $out = Artisan::output();

    expect($out)->toContain('Publish queue')
        ->and($out)->toContain('2 stuck at "queued to publish"')
        ->and($out)->toContain('launchpad:drain-publish '.$site->id);
});

test('blog-status flags a post drafted before the hero-image fix', function () {
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published,
        'slug' => 'old-post', 'title' => 'Old Post', 'wp_post_id' => 7, 'meta' => ['image_specs' => []],
    ]);

    Artisan::call('launchpad:blog-status', ['site' => $site->id]);

    expect(Artisan::output())->toContain('NO hero spec');
});
