<?php

use App\ContentEngine\Feeds\FeedHealth;
use App\Enums\FeedOrigin;
use App\Enums\FeedStatus;
use App\Models\Source;

function health(): FeedHealth
{
    return new FeedHealth(21);
}

function feed(array $attrs): Source
{
    return Source::factory()->create(array_merge([
        'origin' => FeedOrigin::Client->value,
        'url' => 'https://example.com/feed',
        'enabled' => true,
    ], $attrs));
}

it('reports paused when the feed is disabled', function () {
    expect(health()->status(feed(['enabled' => false])))->toBe(FeedStatus::Paused);
});

it('reports active for a newly added feed not yet polled', function () {
    expect(health()->status(feed(['last_fetched_at' => null])))->toBe(FeedStatus::Active);
});

it('reports unhealthy when the most recent fetch failed', function () {
    $f = feed(['last_fetched_at' => now(), 'last_error' => 'HTTP 500']);

    expect(health()->status($f))->toBe(FeedStatus::Unhealthy);
});

it('reports unhealthy when there has been no item for longer than the window', function () {
    $f = feed(['last_fetched_at' => now(), 'last_error' => null, 'last_item_at' => now()->subDays(30)]);

    expect(health()->status($f))->toBe(FeedStatus::Unhealthy);
});

it('reports active when a recent item arrived', function () {
    $f = feed(['last_fetched_at' => now(), 'last_error' => null, 'last_item_at' => now()->subDay()]);

    expect(health()->status($f))->toBe(FeedStatus::Active);
});
