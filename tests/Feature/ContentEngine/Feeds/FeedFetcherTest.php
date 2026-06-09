<?php

use App\ContentEngine\Feeds\FeedFetcher;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;
use Tests\Support\Feeds;

function feedFetcher(): FeedFetcher
{
    return new FeedFetcher(app(Http::class), 30, 100);
}

it('fetches a client direct feed plain (no consent cookie) and takes the url from <link>', function () {
    HttpFacade::fake(['*' => HttpFacade::response(Feeds::directXml(), 200, ['Content-Type' => 'application/rss+xml'])]);

    $result = feedFetcher()->fetch(Feeds::client('https://techcrunch.com/feed/'));

    expect($result->ok())->toBeTrue()
        ->and($result->items)->toHaveCount(1)
        ->and($result->items[0]->url)->toBe('https://techcrunch.com/2026/gadget')   // straight from <link>
        ->and($result->items[0]->sourceName)->toBe('TechCrunch')                    // channel <title>
        ->and($result->items[0]->externalId)->toStartWith('feed:');

    HttpFacade::assertSent(function ($request) {
        return empty($request->header('Cookie'))                                    // consent recipe must NOT leak
            && str_starts_with((string) ($request->header('User-Agent')[0] ?? ''), 'Mozilla/5.0');
    });
});

it('fetches a google news feed with the consent cookie and nulls opaque article links', function () {
    $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Google News</title>'
        .'<item><title>Opaque token item</title><link>'.Feeds::gnewsOpaqueLink().'</link>'
        .'<pubDate>Mon, 01 Jun 2026 10:00:00 GMT</pubDate><source url="https://tribune.com">Austin Tribune</source></item>'
        .'<item><title>Clean wrapper item</title><link>https://www.google.com/url?url=https://example.com/a&amp;ct=ga</link>'
        .'<pubDate>Mon, 01 Jun 2026 11:00:00 GMT</pubDate><source url="https://example.com">Example</source></item>'
        .'</channel></rss>';

    HttpFacade::fake(['*' => HttpFacade::response($xml, 200, ['Content-Type' => 'application/xml'])]);

    $result = feedFetcher()->fetch(Feeds::client('https://news.google.com/rss/search?q=plumbing'));

    expect($result->ok())->toBeTrue()
        ->and($result->items[0]->url)->toBeNull()                  // opaque token — cite by name, no decoder
        ->and($result->items[0]->sourceName)->toBe('Austin Tribune')
        ->and($result->items[0]->externalId)->toStartWith('googlenews:')
        ->and($result->items[1]->url)->toBe('https://example.com/a'); // clean wrapper still resolves

    HttpFacade::assertSent(fn ($request) => str_contains((string) ($request->header('Cookie')[0] ?? ''), 'CONSENT=YES'));
});

it('reports an HTML consent page as a fetch error, never a silent empty', function () {
    HttpFacade::fake(['*' => HttpFacade::response('<!doctype html><html><title>Before you continue</title></html>', 200, ['Content-Type' => 'text/html'])]);

    $result = feedFetcher()->fetch(Feeds::client('https://news.google.com/rss/search?q=x'));

    expect($result->ok())->toBeFalse()
        ->and($result->format)->toBe('html')
        ->and($result->items)->toBe([])
        ->and($result->error)->toContain('consent');
});

it('reports a non-feed HTML page on a client url clearly', function () {
    HttpFacade::fake(['*' => HttpFacade::response('<html><body>not a feed</body></html>', 200, ['Content-Type' => 'text/html'])]);

    $result = feedFetcher()->fetch(Feeds::client('https://example.com/not-a-feed'));

    expect($result->ok())->toBeFalse()
        ->and($result->error)->toContain('HTML page');
});

it('reports an HTTP error as a fetch error', function () {
    HttpFacade::fake(['*' => HttpFacade::response('boom', 500)]);

    $result = feedFetcher()->fetch(Feeds::client('https://example.com/feed'));

    expect($result->ok())->toBeFalse()
        ->and($result->status)->toBe(500)
        ->and($result->error)->toContain('HTTP 500');
});

it('errors when the feed has no url', function () {
    HttpFacade::fake();

    $result = feedFetcher()->fetch(Feeds::client(''));

    expect($result->ok())->toBeFalse()->and($result->error)->toContain('no URL');
    HttpFacade::assertNothingSent();
});
