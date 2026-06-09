<?php

use App\Integrations\News\GoogleNewsRssProvider;
use App\Integrations\News\NewsItem;
use App\Integrations\News\NewsSourceException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function googleNewsProvider(): GoogleNewsRssProvider
{
    return new GoogleNewsRssProvider(
        app(Http::class),
        'https://news.google.com',
        'en-US',
        'US',
        'US:en',
        90,
        30,
    );
}

/** A google.news article link whose base64 id decodes to the publisher URL. */
function googleNewsLink(string $publisherUrl): string
{
    $blob = "\x08\x13\x22".chr(strlen($publisherUrl)).$publisherUrl."\xd2\x01\x00";
    $id = rtrim(strtr(base64_encode($blob), '+/', '-_'), '=');

    return "https://news.google.com/rss/articles/{$id}?oc=5";
}

function googleNewsRss(): string
{
    $a = googleNewsLink('https://tribune.com/water-heater-rebate');

    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0"><channel><title>Google News</title>'
        .'<item><title>Rebate explained - Austin Tribune</title>'
        ."<link>{$a}</link>"
        .'<pubDate>Mon, 01 Jun 2026 14:30:00 GMT</pubDate>'
        .'<source url="https://tribune.com">Austin Tribune</source></item>'
        .'<item><title>Tankless tips</title>'
        .'<link>https://www.google.com/url?rct=j&amp;url=https://example.com/tankless&amp;ct=ga</link>'
        .'<pubDate>Mon, 01 Jun 2026 10:00:00 GMT</pubDate>'
        .'<source url="https://example.com">Example Daily</source></item>'
        .'</channel></rss>';
}

function consentHtml(): string
{
    return '<!doctype html><html><head><title>Before you continue to Google</title></head>'
        .'<body><form>consent interstitial</form></body></html>';
}

it('parses Google News RSS into NewsItems and unwraps to the publisher URL', function () {
    HttpFacade::fake([
        '*/rss/search*' => HttpFacade::response(googleNewsRss(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']),
    ]);

    $items = googleNewsProvider()->fetch(['query' => 'water heater repair austin', 'topic' => 'Water Heaters']);

    expect($items)->toHaveCount(2);
    $first = $items[0];
    expect($first)->toBeInstanceOf(NewsItem::class)
        ->and($first->url)->toBe('https://tribune.com/water-heater-rebate')   // unwrapped, not news.google.com
        ->and($first->title)->toBe('Rebate explained - Austin Tribune')
        ->and($first->sourceName)->toBe('Austin Tribune')
        ->and($first->topic)->toBe('Water Heaters')
        ->and($first->externalId)->toStartWith('googlenews:');

    // The google.com/url?...&url= wrapper is decoded too.
    expect($items[1]->url)->toBe('https://example.com/tankless');
});

it('sends the consent cookie, hl/gl/ceid params and a browser User-Agent', function () {
    HttpFacade::fake(['*/rss/search*' => HttpFacade::response(googleNewsRss(), 200, ['Content-Type' => 'application/xml'])]);

    googleNewsProvider()->fetch(['query' => 'plumbing']);

    HttpFacade::assertSent(function ($request) {
        $url = urldecode($request->url());

        return str_contains((string) ($request->header('Cookie')[0] ?? ''), 'CONSENT=YES')
            && str_contains((string) ($request->header('Cookie')[0] ?? ''), 'SOCS=')
            && str_starts_with((string) ($request->header('User-Agent')[0] ?? ''), 'Mozilla/5.0')
            && str_contains($url, 'hl=en-US')
            && str_contains($url, 'gl=US')
            && str_contains($url, 'ceid=US:en')
            && str_contains($url, 'when:'); // recency operator
    });
});

it('surfaces an HTML consent page loudly — never a silent empty', function () {
    HttpFacade::fake(['*/rss/search*' => HttpFacade::response(consentHtml(), 200, ['Content-Type' => 'text/html'])]);

    expect(fn () => googleNewsProvider()->fetch(['query' => 'plumbing']))
        ->toThrow(NewsSourceException::class);
});

it('diagnose reports xml shape with an item count (LIVE signal)', function () {
    HttpFacade::fake(['*/rss/search*' => HttpFacade::response(googleNewsRss(), 200, ['Content-Type' => 'application/xml'])]);

    $d = googleNewsProvider()->diagnose();

    expect($d['format'])->toBe('xml')
        ->and($d['items'])->toBe(2)
        ->and($d['status'])->toBe(200)
        ->and($d['sample'])->not->toBe('');
});

it('diagnose reports html shape for a consent page (FAIL signal, not false LIVE)', function () {
    HttpFacade::fake(['*/rss/search*' => HttpFacade::response(consentHtml(), 200, ['Content-Type' => 'text/html'])]);

    $d = googleNewsProvider()->diagnose();

    expect($d['format'])->toBe('html')
        ->and($d['items'])->toBe(0);
});
