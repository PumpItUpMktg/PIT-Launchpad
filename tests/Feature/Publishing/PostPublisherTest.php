<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\PostPublisher;
use Illuminate\Support\Facades\Http;

function verifiedSite(): Site
{
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    Connection::factory()->rotated()->create([
        'site_id' => $site->id,
        'provider' => ConnectionProvider::WpAppPassword->value,
        'credentials' => ['base_url' => 'https://apex.example', 'username' => 'launchpad-sync', 'app_password' => 'pw'],
    ]);

    return $site;
}

function approvedPost(Site $site): Content
{
    return Content::factory()->post()->create([
        'site_id' => $site->id,
        'status' => ContentStatus::Approved,
        'title' => 'Heat pump rebate expands',
        'slug' => 'heat-pump-rebate-expands',
    ]);
}

it('publishes an approved post to WordPress and stores the wp id', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 108, 'status' => 'publish', 'skipped' => false])]);
    $site = verifiedSite();
    $post = approvedPost($site);

    $result = app(PostPublisher::class)->publish($post);

    expect($result->isPublished())->toBeTrue()
        ->and($result->wpPostId)->toBe(108)
        ->and($post->fresh()->status)->toBe(ContentStatus::Published);
});

it('honors a {skipped:true} response — does not clobber a WordPress edit', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 0, 'status' => 'draft', 'skipped' => true])]);
    $site = verifiedSite();
    $post = approvedPost($site);

    $result = app(PostPublisher::class)->publish($post);

    expect($result->wasSkipped())->toBeTrue()
        ->and($post->fresh()->locally_edited)->toBeTrue();
});

it('is idempotent — re-publishing pushes by content_id without duplicating', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 108, 'status' => 'publish', 'skipped' => false])]);
    $site = verifiedSite();
    $post = approvedPost($site);

    app(PostPublisher::class)->publish($post);
    $second = app(PostPublisher::class)->publish($post->fresh());

    expect($second->isPublished())->toBeTrue()
        ->and($second->wpPostId)->toBe(108)
        ->and(Content::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('refuses to publish without a present, non-compromised connection', function () {
    Http::fake();
    $site = Site::factory()->create();
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword->value]);
    $post = approvedPost($site);

    $result = app(PostPublisher::class)->publish($post);

    expect($result->hasFailed())->toBeTrue()
        ->and($result->message)->toContain('connection');
    Http::assertNothingSent();
});

it('publishes via the launchpad:publish-content command and refuses when blocked', function () {
    Http::fake(['*/launchpad/v1/content' => Http::response(['wp_post_id' => 108, 'status' => 'publish', 'skipped' => false])]);
    $site = verifiedSite();
    $post = approvedPost($site);

    $this->artisan('launchpad:publish-content', ['content' => $post->id])->assertSuccessful();

    $blockedSite = Site::factory()->create();
    Connection::factory()->compromised()->create(['site_id' => $blockedSite->id, 'provider' => ConnectionProvider::WpAppPassword->value]);
    $blocked = approvedPost($blockedSite);

    $this->artisan('launchpad:publish-content', ['content' => $blocked->id])->assertFailed();
});
