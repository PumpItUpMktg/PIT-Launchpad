<?php

namespace App\ContentEngine\Feeds;

use App\Integrations\News\NewsItem;
use App\Integrations\News\RssFeed;
use App\Models\Source;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The single per-feed fetch path — the only place the two origins differ. Fetch
 * strategy branches on the URL HOST, not on origin: news.google.com gets the
 * consent recipe (cookie + browser UA) that flips off the datacenter-IP wall;
 * every other host (client direct RSS/Atom) gets plain + UA. Both converge on
 * RssFeed::parse and emit identical NewsItems, after which origin is invisible.
 *
 * A body that isn't real XML-with-items (a consent page, an HTML error, an empty
 * response) is reported as a fetch error for the health badge — never a silent
 * empty that masquerades as a healthy feed.
 */
class FeedFetcher
{
    public function __construct(
        private readonly Http $http,
        private readonly int $timeout = 30,
        private readonly int $maxItems = 100,
    ) {}

    public function fetch(Source $feed): FeedFetchResult
    {
        $url = trim((string) ($feed->url ?? ''));
        if ($url === '') {
            return new FeedFetchResult([], 'unknown', 0, 'Feed has no URL.');
        }

        try {
            $response = $this->request($url);
        } catch (Throwable $e) {
            return new FeedFetchResult([], 'unknown', 0, $this->shorten($e->getMessage()));
        }

        $status = $response->status();
        if (! $response->successful()) {
            return new FeedFetchResult([], 'unknown', $status, "HTTP {$status}");
        }

        $body = $response->body();
        $shape = RssFeed::shape((string) $response->header('Content-Type'), $body);

        if ($shape['format'] !== 'xml') {
            return new FeedFetchResult([], $shape['format'], $status, $this->shapeError($shape['format'], $url));
        }

        return new FeedFetchResult($this->map($feed, $url, $body), 'xml', $status);
    }

    /**
     * @return list<NewsItem>
     */
    private function map(Source $feed, string $url, string $body): array
    {
        $google = $this->isGoogleNews($url);
        $channel = $google ? '' : RssFeed::channelTitle($body);

        $items = [];
        foreach (RssFeed::parse($body) as $row) {
            if ($row['link'] === '') {
                continue;
            }

            if ($google) {
                // No opaque-token decoder: a clean canonical resolves, otherwise
                // url stays null and the item is cited by <source> name.
                $articleUrl = RssFeed::publisherUrl($row['link']);
                $sourceName = $row['source'] !== ''
                    ? $row['source']
                    : ($articleUrl !== null ? (string) (parse_url($articleUrl, PHP_URL_HOST) ?: '') : '');
                $externalId = 'googlenews:'.sha1($row['link']);
            } else {
                // Client direct feed: <link> IS the publisher URL; the channel
                // <title> is the outlet name when per-item <source> is absent.
                $articleUrl = $row['link'];
                $sourceName = $row['source'] !== ''
                    ? $row['source']
                    : ($channel !== '' ? $channel : (string) (parse_url($row['link'], PHP_URL_HOST) ?: ''));
                $externalId = 'feed:'.sha1($row['link']);
            }

            $items[] = new NewsItem(
                externalId: $externalId,
                title: $row['title'],
                summary: $row['summary'],
                sourceName: $sourceName,
                publishedAt: RssFeed::parseDate($row['published']),
                url: $articleUrl,
                body: null,
                topic: null,
                feedId: $feed->id,
            );

            if (count($items) >= $this->maxItems) {
                break;
            }
        }

        return $items;
    }

    private function request(string $url): Response
    {
        $client = $this->http->withUserAgent(RssFeed::USER_AGENT)->timeout($this->timeout);

        // The consent recipe is Google-News-specific — it must NOT leak onto
        // client direct feeds, which fetch plain (UA only).
        if ($this->isGoogleNews($url)) {
            $client = $client->withHeaders(['Cookie' => RssFeed::GOOGLE_CONSENT_COOKIE]);
        }

        return $client->get($url);
    }

    private function isGoogleNews(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        return str_contains($host, 'news.google.com');
    }

    private function shapeError(string $format, string $url): string
    {
        if ($format === 'html') {
            return $this->isGoogleNews($url)
                ? 'Google News returned an HTML consent page (datacenter-IP block?), not RSS.'
                : 'That URL returned an HTML page, not an RSS/Atom feed.';
        }

        return 'The response was empty or not a recognized RSS/Atom feed.';
    }

    private function shorten(string $message): string
    {
        $message = trim($message);

        return strlen($message) > 200 ? substr($message, 0, 197).'...' : $message;
    }
}
