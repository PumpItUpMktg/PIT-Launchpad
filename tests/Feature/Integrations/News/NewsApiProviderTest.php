<?php

use App\Integrations\News\NewsApiProvider;
use App\Integrations\News\NewsItem;
use App\Integrations\News\NewsSourceException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function newsApiProvider(int $recencyDays = 90): NewsApiProvider
{
    return new NewsApiProvider(
        app(Http::class),
        'test-key',
        'https://newsapi.org/v2',
        $recencyDays,
        30,
    );
}

/**
 * @return array<string, mixed>
 */
function newsApiArticle(string $url, string $title = 'A title'): array
{
    return [
        'source' => ['id' => null, 'name' => 'The Tribune'],
        'title' => $title,
        'description' => 'A short snippet.',
        'url' => $url,
        'urlToImage' => 'https://img.example/x.jpg',
        'publishedAt' => '2026-06-05T14:30:00Z',
    ];
}

it('parses a NewsAPI /everything article into the normalized NewsItem', function () {
    HttpFacade::fake([
        '*/everything*' => HttpFacade::response([
            'status' => 'ok',
            'totalResults' => 1,
            'articles' => [newsApiArticle('https://tribune.com/story')],
        ]),
    ]);

    $items = newsApiProvider()->fetch(['query' => 'plumbing', 'topic' => 'Plumbing']);

    expect($items)->toHaveCount(1);
    $item = $items[0];
    expect($item)->toBeInstanceOf(NewsItem::class)
        ->and($item->url)->toBe('https://tribune.com/story')
        ->and($item->title)->toBe('A title')
        ->and($item->sourceName)->toBe('The Tribune')   // source.name
        ->and($item->summary)->toBe('A short snippet.')  // description → summary
        ->and($item->body)->toBeNull()
        ->and($item->topic)->toBe('Plumbing')
        ->and($item->publishedAt->format('Y-m-d'))->toBe('2026-06-05')
        ->and($item->externalId)->toStartWith('newsapi:');
});

it('sends the api key, date window, language and pagination params', function () {
    HttpFacade::fake([
        '*/everything*' => HttpFacade::response(['status' => 'ok', 'totalResults' => 0, 'articles' => []]),
    ]);

    newsApiProvider()->fetch(['query' => 'plumbing', 'language' => 'en']);

    HttpFacade::assertSent(function ($request) {
        $url = urldecode($request->url());

        return $request->hasHeader('X-Api-Key', 'test-key')
            && str_contains($url, 'q=plumbing')
            && str_contains($url, 'language=en')
            && str_contains($url, 'sortBy=publishedAt')
            && str_contains($url, 'from=')
            && str_contains($url, 'page=1');
    });
});

it('paginates when totalResults exceeds a page and max spans pages', function () {
    $page1 = array_map(fn ($i) => newsApiArticle("https://a.com/p1-$i", "t1-$i"), range(1, 100));
    $page2 = array_map(fn ($i) => newsApiArticle("https://a.com/p2-$i", "t2-$i"), range(1, 20));

    $call = 0;
    HttpFacade::fake(function ($request) use (&$call, $page1, $page2) {
        $call++;

        return HttpFacade::response([
            'status' => 'ok',
            'totalResults' => 120,
            'articles' => $call === 1 ? $page1 : $page2,
        ]);
    });

    $items = newsApiProvider()->fetch(['query' => 'plumbing', 'max' => 120]);

    HttpFacade::assertSentCount(2);
    expect($items)->toHaveCount(120);
});

it('throws fatally on a NewsAPI error envelope', function () {
    HttpFacade::fake([
        '*/everything*' => HttpFacade::response([
            'status' => 'error',
            'code' => 'apiKeyInvalid',
            'message' => 'Your API key is invalid.',
        ], 401),
    ]);

    try {
        newsApiProvider()->fetch(['query' => 'plumbing']);
        $this->fail('expected NewsSourceException');
    } catch (NewsSourceException $e) {
        expect($e->fatal)->toBeTrue()
            ->and($e->errorCode)->toBe('apiKeyInvalid');
    }
});

it('throws fatally on a 429 rate-limit', function () {
    HttpFacade::fake([
        '*/everything*' => HttpFacade::response(['status' => 'error', 'code' => 'rateLimited', 'message' => 'too many'], 429),
    ]);

    newsApiProvider()->fetch(['query' => 'plumbing']);
})->throws(NewsSourceException::class);
