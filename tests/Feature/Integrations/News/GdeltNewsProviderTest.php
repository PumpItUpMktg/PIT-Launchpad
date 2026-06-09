<?php

use App\Integrations\News\GdeltNewsProvider;
use App\Integrations\News\GdeltRateLimiter;
use App\Integrations\News\NewsItem;
use App\Integrations\News\NewsSourceException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function gdeltProvider(int $maxRecords = 250, int $recencyDays = 90): GdeltNewsProvider
{
    return new GdeltNewsProvider(
        app(Http::class),
        new GdeltRateLimiter(app(Cache::class), 0), // throttle disabled in tests
        'https://api.gdeltproject.org/api/v2/doc/doc',
        $maxRecords,
        $recencyDays,
        30,
    );
}

/**
 * @return array<string, mixed>
 */
function gdeltArticle(string $url, string $title = 'A title', string $domain = 'tribune.com'): array
{
    return [
        'url' => $url,
        'url_mobile' => '',
        'title' => $title,
        'seendate' => '20260605T143000Z',
        'domain' => $domain,
        'language' => 'English',
        'sourcecountry' => 'US',
        'socialimage' => 'https://img.example/x.jpg',
    ];
}

it('parses a GDELT ArtList article into the normalized NewsItem', function () {
    HttpFacade::fake([
        '*' => HttpFacade::response(['articles' => [gdeltArticle('https://tribune.com/story')]]),
    ]);

    $items = gdeltProvider()->fetch(['query' => 'plumbing', 'topic' => 'Plumbing']);

    expect($items)->toHaveCount(1);
    $item = $items[0];
    expect($item)->toBeInstanceOf(NewsItem::class)
        ->and($item->url)->toBe('https://tribune.com/story')
        ->and($item->title)->toBe('A title')
        ->and($item->sourceName)->toBe('tribune.com')
        ->and($item->summary)->toBe('')          // ArtList has no snippet (finding)
        ->and($item->body)->toBeNull()            // metadata only — body is §6b
        ->and($item->topic)->toBe('Plumbing')
        ->and($item->publishedAt->format('Y-m-d'))->toBe('2026-06-05')
        ->and($item->externalId)->toStartWith('gdelt:');
});

it('builds a quoted/OR query with locale + domain filters and a bounded window', function () {
    HttpFacade::fake(['*' => HttpFacade::response(['articles' => []])]);

    gdeltProvider()->fetch([
        'keywords' => ['water heater repair', 'tankless'],
        'language' => 'english',
        'country' => 'US',
        'domains' => ['a.com'],
    ]);

    HttpFacade::assertSent(function ($request) {
        $url = urldecode($request->url());

        return str_contains($url, '("water heater repair" OR tankless)')
            && str_contains($url, 'sourcelang:english')
            && str_contains($url, 'sourcecountry:US')
            && str_contains($url, 'domainis:a.com')
            && str_contains($url, 'mode=artlist')
            && str_contains($url, 'format=json')
            && str_contains($url, 'startdatetime=')
            && str_contains($url, 'enddatetime=');
    });
});

it('slices the time window when a window saturates maxrecords', function () {
    $calls = 0;
    HttpFacade::fake(function ($request) use (&$calls) {
        $calls++;

        // First (full-window) request saturates max=2 → forces a bisection; the
        // two narrower child windows each return a single, non-saturating result.
        return $calls === 1
            ? HttpFacade::response(['articles' => [gdeltArticle('https://a.com/1'), gdeltArticle('https://a.com/2')]])
            : HttpFacade::response(['articles' => [gdeltArticle('https://a.com/child'.$calls)]]);
    });

    $items = gdeltProvider(maxRecords: 2)->fetch(['query' => 'plumbing']);

    HttpFacade::assertSentCount(3); // 1 saturated parent + 2 sliced children
    expect($items)->toHaveCount(2)
        ->and($items[0]->url)->toBe('https://a.com/child2');
});

it('dedupes repeated URLs across merged slices', function () {
    $calls = 0;
    HttpFacade::fake(function ($request) use (&$calls) {
        $calls++;

        return $calls === 1
            ? HttpFacade::response(['articles' => [gdeltArticle('https://a.com/1'), gdeltArticle('https://a.com/2')]])
            : HttpFacade::response(['articles' => [gdeltArticle('https://a.com/dupe')]]);
    });

    // Both child windows return the same URL → deduped to one.
    $items = gdeltProvider(maxRecords: 2)->fetch(['query' => 'plumbing']);

    expect($items)->toHaveCount(1)
        ->and($items[0]->url)->toBe('https://a.com/dupe');
});

it('surfaces a non-JSON GDELT body as a NewsSourceException (no parser crash)', function () {
    HttpFacade::fake([
        '*' => HttpFacade::response('You have exceeded the rate limit. Please wait.', 200),
    ]);

    gdeltProvider()->fetch(['query' => 'plumbing']);
})->throws(NewsSourceException::class);

it('throws when the query is too short / empty to build', function () {
    HttpFacade::fake();

    gdeltProvider()->fetch(['keywords' => ['', '  ']]);
})->throws(NewsSourceException::class);
