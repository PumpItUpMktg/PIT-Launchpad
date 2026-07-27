<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressClientFactory;
use App\Integrations\Wordpress\WordpressException;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\DeleteFromWordpress;

it('makes a page republishable with no WP call when it was never published there', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'wp_post_id' => null, 'status' => ContentStatus::PublishFailed,
    ]);

    $result = app(DeleteFromWordpress::class)->delete($page->fresh());

    expect($result['on_wp'])->toBeFalse()
        ->and($result['deleted'])->toBeFalse();

    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($page->id);
    expect($fresh->status)->toBe(ContentStatus::Approved)
        ->and($fresh->slug)->toBe('drain-cleaning'); // slug preserved for re-publish
});

it('sends a taken-down BLOG POST back to candidate (re-enters the funnel), not queued-for-publish', function () {
    $site = Site::factory()->create();
    $post = Content::factory()->post()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Post,
        'slug' => 'why-your-sump-pump-runs-constantly', 'wp_post_id' => 77, 'status' => ContentStatus::Published,
    ]);

    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('deleteContent')->once()->andReturnTrue();
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $result = app(DeleteFromWordpress::class)->delete($post->fresh());

    expect($result['deleted'])->toBeTrue();
    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($post->id);
    expect($fresh->status)->toBe(ContentStatus::Candidate)   // back in the funnel, NOT approved/queued
        ->and($fresh->wp_post_id)->toBeNull()
        ->and($fresh->slug)->toBe('why-your-sump-pump-runs-constantly'); // slug preserved
});

it('a PAGE take-down still returns to approved (Repush recreates on the same URL)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'slug' => 'edison-nj', 'wp_post_id' => null, 'status' => ContentStatus::Published,
    ]);

    app(DeleteFromWordpress::class)->delete($page->fresh());

    expect(Content::withoutGlobalScope(SiteScope::class)->find($page->id)->status)->toBe(ContentStatus::Approved);
});

it('surfaces WHY a live take-down failed and leaves the page untouched (still on WP)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'wp_post_id' => 42, 'status' => ContentStatus::Published,
    ]);

    // The WP delete is rejected (e.g. the connection user can't delete the post) — the client throws
    // with the reason, which the take-down must report verbatim rather than a bare "did not confirm".
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('deleteContent')->once()->with($page->id)
        ->andThrow(new WordpressException('WordPress delete of '.$page->id.' returned HTTP 403 — Sorry, you are not allowed to delete this post.'));
    $factory = Mockery::mock(WordpressClientFactory::class);
    $factory->shouldReceive('forSite')->once()->andReturn($client);
    app()->instance(WordpressClientFactory::class, $factory);

    $result = app(DeleteFromWordpress::class)->delete($page->fresh());

    expect($result['deleted'])->toBeFalse()
        ->and($result['on_wp'])->toBeTrue()
        ->and($result['message'])->toContain('HTTP 403')                             // the status...
        ->and($result['message'])->toContain('not allowed to delete this post');     // ...and WP's reason

    // The page is left exactly as it was — a failed take-down must NOT strand it as republishable while
    // the live post is still up.
    $fresh = Content::withoutGlobalScope(SiteScope::class)->find($page->id);
    expect($fresh->wp_post_id)->toBe(42)
        ->and($fresh->status)->toBe(ContentStatus::Published);
});
