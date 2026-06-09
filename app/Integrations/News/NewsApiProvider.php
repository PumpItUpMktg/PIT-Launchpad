<?php

namespace App\Integrations\News;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\Factory as Http;

/**
 * Live NewsAPI `/v2/everything` source (the §6a configured alternate). Keyed via
 * `X-Api-Key`, with real `page`/`pageSize` pagination (unlike GDELT). Emits
 * candidate metadata + the article URL only — full-article fetch is the
 * downstream §6b grounding step.
 *
 * Production licensing note: NewsAPI's free Developer plan is dev/testing only
 * (24h delay, 100 req/day, no production use). `NEWS_PROVIDER=newsapi` in
 * production assumes a paid Business plan — the adapter does not enforce billing.
 */
class NewsApiProvider implements NewsProvider
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly Http $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $recencyDays,
        private readonly int $timeout = 30,
    ) {}

    /**
     * @param  array<string, mixed>  $feedConfig
     * @return list<NewsItem>
     */
    public function fetch(array $feedConfig, ?DateTimeInterface $since = null): array
    {
        $query = $this->buildQuery($feedConfig);
        $max = (int) ($feedConfig['max'] ?? self::PAGE_SIZE);
        $max = max(1, $max);
        $topic = isset($feedConfig['topic']) ? (string) $feedConfig['topic'] : null;

        $utc = new DateTimeZone('UTC');
        $to = new DateTimeImmutable('now', $utc);
        $from = $since !== null
            ? DateTimeImmutable::createFromInterface($since)->setTimezone($utc)
            : $to->modify('-'.$this->recencyDays.' days');

        $params = [
            'q' => $query,
            'from' => $from->format('Y-m-d\TH:i:s'),
            'to' => $to->format('Y-m-d\TH:i:s'),
            'sortBy' => 'publishedAt',
            'pageSize' => min($max, self::PAGE_SIZE),
        ];
        if (isset($feedConfig['language']) && is_string($feedConfig['language']) && $feedConfig['language'] !== '') {
            $params['language'] = $feedConfig['language'];
        }

        $items = [];
        $page = 1;
        do {
            $batch = $this->requestPage($params, $page);
            foreach ($batch['articles'] as $article) {
                $items[] = $article;
            }
            $page++;
            $collected = count($items);
        } while ($batch['articles'] !== [] && $collected < $max && $collected < $batch['total']);

        return $this->dedupe(array_slice($this->mapAll($items, $topic), 0, $max));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{articles: list<array<string, mixed>>, total: int}
     */
    private function requestPage(array $params, int $page): array
    {
        $response = $this->http
            ->timeout($this->timeout)
            ->withHeaders(['X-Api-Key' => $this->apiKey])
            ->get(rtrim($this->baseUrl, '/').'/everything', $params + ['page' => $page]);

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        // NewsAPI surfaces failures as a JSON error envelope (and 401/426/429).
        if (($data['status'] ?? null) === 'error' || ! $response->successful()) {
            $code = (string) ($data['code'] ?? $response->status());

            throw new NewsSourceException(
                'NewsAPI error ['.$code.']: '.(string) ($data['message'] ?? $response->status()),
                $code,
                fatal: in_array($response->status(), [401, 426, 429], true)
                    || in_array($code, ['apiKeyInvalid', 'apiKeyMissing', 'apiKeyDisabled', 'rateLimited', 'maximumResultsReached'], true),
            );
        }

        /** @var list<array<string, mixed>> $articles */
        $articles = array_values(array_filter((array) ($data['articles'] ?? []), 'is_array'));

        return ['articles' => $articles, 'total' => (int) ($data['totalResults'] ?? 0)];
    }

    /**
     * @param  list<array<string, mixed>>  $articles
     * @return list<NewsItem>
     */
    private function mapAll(array $articles, ?string $topic): array
    {
        $items = [];
        foreach ($articles as $article) {
            $url = (string) ($article['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $source = $article['source'] ?? [];
            $sourceName = is_array($source) ? (string) ($source['name'] ?? '') : '';
            if ($sourceName === '') {
                $sourceName = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            }

            $items[] = new NewsItem(
                externalId: 'newsapi:'.sha1($url),
                title: (string) ($article['title'] ?? ''),
                summary: (string) ($article['description'] ?? ''),
                sourceName: $sourceName,
                publishedAt: $this->parsePublishedAt((string) ($article['publishedAt'] ?? '')),
                url: $url,
                body: null,
                topic: $topic,
            );
        }

        return $items;
    }

    private function parsePublishedAt(string $value): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        try {
            return new DateTimeImmutable($value !== '' ? $value : 'now', $utc);
        } catch (\Exception) {
            return new DateTimeImmutable('now', $utc);
        }
    }

    /**
     * @param  array<string, mixed>  $feedConfig
     */
    private function buildQuery(array $feedConfig): string
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
            throw new NewsSourceException('NewsAPI query is empty — provide a query, keywords, or topics.');
        }

        return $base;
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
