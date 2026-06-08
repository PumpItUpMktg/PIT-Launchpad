<?php

use App\Integrations\Wordpress\WordpressClient;
use App\Integrations\Wordpress\WordpressException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function wpClient(): WordpressClient
{
    return new WordpressClient(app(Factory::class), 'https://wp.test', 'svc-user', 'app-pass');
}

test('upsertContent posts to /content with the control-plane ULID and basic auth', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/content' => Http::response(
            ['content_id' => '01CONTENT', 'wp_post_id' => 42, 'status' => 'publish', 'skipped' => false],
            200,
        ),
    ]);

    $result = wpClient()->upsertContent(['content_id' => '01CONTENT', 'slug' => 'x']);

    expect($result['wp_post_id'])->toBe(42);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/wp-json/launchpad/v1/content')
            && $request['content_id'] === '01CONTENT'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('svc-user:app-pass'));
    });
});

test('a transient 5xx is retried and the same ULID is re-sent (idempotent, no duplicate)', function () {
    Http::fake([
        '*/wp-json/launchpad/v1/content' => Http::sequence()
            ->push('', 503)
            ->push(['content_id' => '01CONTENT', 'wp_post_id' => 7, 'skipped' => false], 200),
    ]);

    $result = wpClient()->upsertContent(['content_id' => '01CONTENT']);

    expect($result['wp_post_id'])->toBe(7);

    // Two attempts, both carrying the same ULID — a retry updates, never duplicates.
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['content_id'] === '01CONTENT');
});

test('a persistent 5xx surfaces a WordpressException after the bounded retries', function () {
    Http::fake(['*/wp-json/launchpad/v1/content' => Http::response('', 500)]);

    expect(fn () => wpClient()->upsertContent(['content_id' => '01CONTENT']))
        ->toThrow(WordpressException::class);

    Http::assertSentCount(3);
});

test('upsertSilo and upsertRedirects hit their endpoints', function () {
    Http::fake([
        '*/launchpad/v1/silo' => Http::response(['silo_id' => '01SILO', 'wp_category_id' => 12], 200),
        '*/launchpad/v1/redirects' => Http::response(['count' => 2], 200),
    ]);

    expect(wpClient()->upsertSilo(['silo_id' => '01SILO', 'name' => 'Plumbing'])['wp_category_id'])->toBe(12)
        ->and(wpClient()->upsertRedirects([['from_url' => '/a', 'to_url' => '/b', 'code' => 301]])['count'])->toBe(2);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/launchpad/v1/redirects') && $r['redirects'][0]['from_url'] === '/a');
});

test('ping returns true on an authed 200', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200)]);

    expect(wpClient()->ping())->toBeTrue();
});
