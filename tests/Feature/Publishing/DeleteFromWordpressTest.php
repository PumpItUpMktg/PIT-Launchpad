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

it('surfaces WHY a live take-down failed and leaves the page untouched (still on WP)', function () {
    $site = Site::factory()->create();
    $page = Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Service,
        'slug' => 'drain-cleaning', 'wp_post_id' => 42, 'status' => ContentStatus::Published,
    ]);

    // The WP delete is rejected (e.g. the connection user can't delete the post) — the client throws
    // with the reason, which the take-down must report verbatim rather than a bare "did not confirm".
    $client = Mockery::mock(WordpressClient::class);
    $client->shouldReceive('forceDeletePost')->once()->with('pages', 42)
        ->andThrow(new WordpressException('WordPress delete of pages 42 returned HTTP 403 — Sorry, you are not allowed to delete this post.'));
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
