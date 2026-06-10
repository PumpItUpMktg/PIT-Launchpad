<?php

namespace App\Integrations\News;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Live Google News RSS source (the §6a default) — beats the datacenter-IP wall
 * GDELT hits. Emits candidate metadata + the article URL only; full-text scrape
 * stays downstream in §6b.
 *
 * The unblock (ported from news-post PR #18): Google serves a consent/interstitial
 * HTML page (HTTP 200, HTML not XML) to datacenter IPs lacking browser cookies, so
 * the fetch is consent-aware — a CONSENT/SOCS cookie + hl/gl/ceid params + a
 * browser User-Agent flips Google to real RSS. A body that comes back as the HTML
 * consent page is surfaced as a NewsSourceException, NEVER a silent empty.
 *
 * Item links are unwrapped to the real publisher URL (not the google.com redirect)
 * so source citations point at the original outlet.
 */
class GoogleNewsRssProvider implements NewsProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $hl,
        private readonly string $gl,
        private readonly string $ceid,
        private readonly int $recencyDays,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $feedConfig
     * @return list<NewsItem>
     */
    public function fetch(array $feedConfig, ?DateTimeInterface $since = null): array
    {
        $query = $this->buildQuery($feedConfig, $since);
        $max = (int) ($feedConfig['max'] ?? 100);
        $topic = isset($feedConfig['topic']) ? (string) $feedConfig['topic'] : null;
        $feedId = isset($feedConfig['feed_id']) ? (string) $feedConfig['feed_id'] : null;

        [$response, $shape] = $this->request($query);

        // A consent/HTML interstitial parses to zero items — surface it loudly so
        // it can never masquerade as a healthy empty feed.
        if ($shape['format'] === 'html') {
            throw new NewsSourceException(
                'Google News returned an HTML consent page (datacenter-IP block?) — not RSS. Needs a fresh SOCS token or a residential-reputation egress.',
            );
        }

        $items = [];
        foreach (RssFeed::parse($response->body()) as $row) {
            if ($row['link'] === '') {
                continue;
            }

            // Decision #1: no opaque-token decoder. A clean canonical resolves to
            // the publisher; a modern opaque token stays null — cite by <source>.
            $url = RssFeed::publisherUrl($row['link']);
            $items[] = new NewsItem(
                externalId: 'googlenews:'.sha1($row['link']),
                title: $row['title'],
                summary: $row['summary'],
                sourceName: $row['source'] !== '' ? $row['source'] : ($url !== null ? (string) (parse_url($url, PHP_URL_HOST) ?: '') : ''),
                publishedAt: RssFeed::parseDate($row['published']),
                url: $url,
                body: null,
                topic: $topic,
                feedId: $feedId,
            );

            if (count($items) >= $max) {
                break;
            }
        }

        return $this->dedupe($items);
    }

    /**
     * One-shot diagnostic for the verify-vendors probe: returns the HTTP status,
     * content-type, body-shape (xml/html/empty), parsed item count, and a sample
     * title — so the probe can FAIL on a consent page instead of false-greening.
     *
     * @return array{status: int, content_type: string, format: string, items: int, sample: string}
     */
    public function diagnose(): array
    {
        [$response, $shape] = $this->request('plumbing');
        $sample = $shape['items'] > 0 ? (RssFeed::parse($response->body())[0]['title'] ?? '') : '';

        return [
            'status' => $response->status(),
            'content_type' => (string) $response->header('Content-Type'),
            'format' => $shape['format'],
            'items' => $shape['items'],
            'sample' => $sample,
        ];
    }

    /**
     * @return array{0: Response, 1: array{format: string, items: int}}
     */
    private function request(string $query): array
    {
        $response = $this->http
            ->withUserAgent(RssFeed::USER_AGENT)
            ->withHeaders(['Cookie' => RssFeed::GOOGLE_CONSENT_COOKIE])
            ->timeout($this->timeout)
            ->get(rtrim($this->baseUrl, '/').'/rss/search', [
                'q' => $query,
                'hl' => $this->hl,
                'gl' => $this->gl,
                'ceid' => $this->ceid,
            ]);

        if (! $response->successful()) {
            throw new NewsSourceException(
                'Google News HTTP '.$response->status(),
                (string) $response->status(),
                fatal: in_array($response->status(), [401, 403], true),
            );
        }

        return [$response, RssFeed::shape((string) $response->header('Content-Type'), $response->body())];
    }

    /**
     * Build the Google News query from the same §6a input GDELT gets (query, or
     * keywords/topics), plus a recency `when:Nd` operator. Locale is the hl/gl/ceid
     * params, not query operators.
     *
     * @param  array<string, mixed>  $feedConfig
     */
    private function buildQuery(array $feedConfig, ?DateTimeInterface $since): string
    {
        $base = isset($feedConfig['query']) ? trim((string) $feedConfig['query']) : '';

        if ($base === '') {
            $terms = array_values(array_filter(array_map(
                fn ($t) => trim((string) $t),
                (array) ($feedConfig['keywords'] ?? $feedConfig['topics'] ?? []),
            ), fn (string $t) => $t !== ''));

            $rendered = array_map(fn (string $t) => str_contains($t, ' ') ? '"'.$t.'"' : $t, $terms);
            $base = count($rendered) <= 1 ? implode('', $rendered) : '('.implode(' OR ', $rendered).')';
        }

        if ($base === '') {
            throw new NewsSourceException('Google News query is empty — provide a query, keywords, or topics.');
        }

        $days = $this->recencyDays;
        if ($since !== null) {
            $elapsed = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $since->getTimestamp();
            $days = max(1, (int) ceil($elapsed / 86400));
        }

        return $base.' when:'.$days.'d';
    }

    /**
     * @param  list<NewsItem>  $items
     * @return list<NewsItem>
     */
    private function dedupe(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = $item->url !== null && $item->url !== ''
                ? strtolower($item->url)
                : strtolower($item->sourceName.'|'.$item->title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }
}
