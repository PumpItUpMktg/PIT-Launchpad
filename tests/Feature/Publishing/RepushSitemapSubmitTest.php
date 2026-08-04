<?php

use App\Enums\ContentKind;
use App\Integrations\SearchConsole\SitemapSubmitter;
use App\Jobs\SubmitSitemap;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\RepushPublished;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function rsSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post->value, 'status' => 'published',
        'wp_post_id' => 5, 'title' => 'Post', 'slug' => 'post-a',
    ]);

    return $site;
}

/** Bind a SitemapSubmitter whose connected() we control, so the test doesn't need a live Google grant. */
function fakeSitemapSubmitter(bool $connected): void
{
    $mock = Mockery::mock(SitemapSubmitter::class);
    $mock->shouldReceive('connected')->andReturn($connected);
    app()->instance(SitemapSubmitter::class, $mock);
}

it('queues a single sitemap submit after a repush when GSC is connected', function () {
    Queue::fake();
    config(['services.google.sitemap_submit_on_repush' => true]);
    fakeSitemapSubmitter(connected: true);
    $site = rsSite();

    $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Post]);

    expect($result['sitemap_submitted'])->toBeTrue();
    Queue::assertPushed(SubmitSitemap::class, 1);
});

it('does not submit the sitemap when GSC is not connected', function () {
    Queue::fake();
    fakeSitemapSubmitter(connected: false);
    $site = rsSite();

    $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Post]);

    expect($result['sitemap_submitted'])->toBeFalse();
    Queue::assertNotPushed(SubmitSitemap::class);
});

it('debounces a second repush within the window (no double submit)', function () {
    Queue::fake();
    config(['services.google.sitemap_submit_on_repush' => true, 'services.google.sitemap_submit_debounce_hours' => 12]);
    fakeSitemapSubmitter(connected: true);
    $site = rsSite();

    $first = app(RepushPublished::class)->dispatch($site, [ContentKind::Post]);
    $second = app(RepushPublished::class)->dispatch($site, [ContentKind::Post]);

    expect($first['sitemap_submitted'])->toBeTrue()
        ->and($second['sitemap_submitted'])->toBeFalse();
    Queue::assertPushed(SubmitSitemap::class, 1); // only the first run
});

it('does not submit the sitemap on a dry run', function () {
    Queue::fake();
    fakeSitemapSubmitter(connected: true);
    $site = rsSite();

    $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Post], dryRun: true);

    expect($result['sitemap_submitted'])->toBeFalse();
    Queue::assertNotPushed(SubmitSitemap::class);
});

it('honors the config flag being off', function () {
    Queue::fake();
    config(['services.google.sitemap_submit_on_repush' => false]);
    fakeSitemapSubmitter(connected: true);
    $site = rsSite();

    $result = app(RepushPublished::class)->dispatch($site, [ContentKind::Post]);

    expect($result['sitemap_submitted'])->toBeFalse();
    Queue::assertNotPushed(SubmitSitemap::class);
});

afterEach(function () {
    Cache::flush();
});
