<?php

use App\ContentEngine\Feeds\FeedFetcher;
use App\ContentEngine\Feeds\FeedValidator;
use App\Enums\FeedOrigin;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Support\Facades\Http as HttpFacade;
use Tests\Support\Feeds;

function feedValidator(int $cap = 25): FeedValidator
{
    return new FeedValidator(app(FeedFetcher::class), $cap);
}

it('returns a publisher + sample-headline preview for a reachable feed', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/rss+xml'])]);
    $site = Site::factory()->create();

    $preview = feedValidator()->validate($site->id, 'https://techcrunch.com/feed/');

    expect($preview->valid)->toBeTrue()
        ->and($preview->publisher)->toBe('TechCrunch')
        ->and($preview->samples)->toContain('A gadget launches today');
});

it('rejects a non-http url without making any request', function () {
    HttpFacade::fake();
    $site = Site::factory()->create();

    $preview = feedValidator()->validate($site->id, 'not-a-url');

    expect($preview->valid)->toBeFalse()->and($preview->error)->toContain('valid http');
    HttpFacade::assertNothingSent();
});

it('rejects a url that returns an HTML page, surfacing the fetch error', function () {
    HttpFacade::fake(['*' => HttpFacade::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);
    $site = Site::factory()->create();

    $preview = feedValidator()->validate($site->id, 'https://example.com/page');

    expect($preview->valid)->toBeFalse()->and($preview->error)->toContain('HTML page');
});

it('rejects a reachable feed that has no items', function () {
    HttpFacade::fake(['*' => HttpFacade::response('<?xml version="1.0"?><rss version="2.0"><channel><title>Empty</title></channel></rss>', 200, ['Content-Type' => 'application/xml'])]);
    $site = Site::factory()->create();

    $preview = feedValidator()->validate($site->id, 'https://example.com/empty');

    expect($preview->valid)->toBeFalse()->and($preview->error)->toContain('no items');
});

it('enforces the per-site soft cap before fetching', function () {
    HttpFacade::fake();
    $site = Site::factory()->create();
    Source::factory()->count(2)->create(['site_id' => $site->id, 'origin' => FeedOrigin::Client->value]);

    $preview = feedValidator(cap: 2)->validate($site->id, 'https://example.com/feed');

    expect($preview->valid)->toBeFalse()->and($preview->error)->toContain('limit');
    HttpFacade::assertNothingSent();
});

it('counts only this site\'s client feeds toward the cap', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/xml'])]);
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    Source::factory()->count(2)->create(['site_id' => $other->id, 'origin' => FeedOrigin::Client->value]);
    Source::factory()->create(['site_id' => $site->id, 'origin' => FeedOrigin::Generated->value]); // generated doesn't count

    $preview = feedValidator(cap: 2)->validate($site->id, 'https://techcrunch.com/feed/');

    expect($preview->valid)->toBeTrue();
});
