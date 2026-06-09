<?php

namespace App\Integrations\News;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;

/**
 * Live GDELT DOC 2.0 news source (the §6a default). No key/auth; emits candidate
 * metadata + the article URL only — full-article fetch is the downstream §6b
 * grounding step, kept out of here.
 *
 * GDELT has no pagination and caps `maxrecords` at 250, so a recency window that
 * saturates is covered by **slicing the time window** (bisect + merge). Requests
 * are throttled through a shared cache-backed limiter. Errors sometimes arrive as
 * plain text/HTML on HTTP 200 — non-JSON bodies are surfaced as a
 * NewsSourceException, never crashed through the parser.
 */
class GdeltNewsProvider implements NewsProvider
{
    /** Hard ceiling on bisection depth so a pathological feed can't fan out forever. */
    private const MAX_SLICE_DEPTH = 6;

    /** Don't bisect below a 15-minute window (GDELT's own resolution floor). */
    private const MIN_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly Http $http,
        private readonly GdeltRateLimiter $limiter,
        private readonly string $baseUrl,
        private readonly int $maxRecords,
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
        $max = (int) ($feedConfig['max'] ?? $this->maxRecords);
        $max = max(1, min($max, 250));
        $topic = isset($feedConfig['topic']) ? (string) $feedConfig['topic'] : null;

        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $since !== null
            ? DateTimeImmutable::createFromInterface($since)->setTimezone(new DateTimeZone('UTC'))
            : $end->modify('-'.$this->recencyDays.' days');

        $items = $this->fetchWindow($query, $start, $end, $max, $topic, 0);

        return $this->dedupe($items);
    }

    /**
     * Fetch one window; if it saturates (hits `maxrecords`) bisect the window and
     * merge — the only way to "page" GDELT.
     *
     * @return list<NewsItem>
     */
    private function fetchWindow(string $query, DateTimeImmutable $start, DateTimeImmutable $end, int $max, ?string $topic, int $depth): array
    {
        $items = $this->parseArtList($this->requestWindow($query, $start, $end, $max), $topic);

        $span = $end->getTimestamp() - $start->getTimestamp();
        if (count($items) >= $max && $depth < self::MAX_SLICE_DEPTH && $span > self::MIN_WINDOW_SECONDS) {
            $mid = $start->modify('+'.intdiv($span, 2).' seconds');

            return array_merge(
                $this->fetchWindow($query, $start, $mid, $max, $topic, $depth + 1),
                $this->fetchWindow($query, $mid, $end, $max, $topic, $depth + 1),
            );
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestWindow(string $query, DateTimeImmutable $start, DateTimeImmutable $end, int $max): array
    {
        $this->limiter->throttle();

        $response = $this->http
            ->timeout($this->timeout)
            ->get($this->baseUrl, [
                'query' => $query,
                'mode' => 'artlist',
                'format' => 'json',
                'sort' => 'datedesc',
                'maxrecords' => $max,
                'startdatetime' => $start->format('YmdHis'),
                'enddatetime' => $end->format('YmdHis'),
            ]);

        if (! $response->successful()) {
            throw new NewsSourceException(
                'GDELT HTTP '.$response->status(),
                (string) $response->status(),
                fatal: in_array($response->status(), [401, 403, 429], true),
            );
        }

        // GDELT returns errors as plain text/HTML even on HTTP 200. A non-JSON
        // body (or a JSON scalar) is surfaced — not run through the parser.
        $body = $response->body();
        if (! str_starts_with(ltrim($body), '{')) {
            throw new NewsSourceException(
                'GDELT returned a non-JSON body: '.Str::limit(trim($body), 160),
            );
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<NewsItem>
     */
    private function parseArtList(array $data, ?string $topic): array
    {
        $articles = $data['articles'] ?? [];
        if (! is_array($articles)) {
            return [];
        }

        $items = [];
        foreach ($articles as $article) {
            if (! is_array($article)) {
                continue;
            }

            $url = (string) ($article['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $items[] = new NewsItem(
                externalId: 'gdelt:'.sha1($url),
                title: (string) ($article['title'] ?? ''),
                // ArtList carries no snippet/description — summary is empty (finding).
                summary: '',
                sourceName: (string) ($article['domain'] ?? ''),
                publishedAt: $this->parseSeendate((string) ($article['seendate'] ?? '')),
                url: $url,
                // body is the downstream §6b grounding concern — metadata + URL only.
                body: null,
                topic: $topic,
            );
        }

        return $items;
    }

    private function parseSeendate(string $seendate): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        // Compact UTC, e.g. 20260605T143000Z.
        $parsed = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $seendate, $utc);

        return $parsed !== false ? $parsed : new DateTimeImmutable('now', $utc);
    }

    /**
     * Build a GDELT DOC query from an explicit `query`, or from
     * keywords/topics + locale filters. Guards against an empty query (GDELT
     * errors on too-short / too-broad input).
     *
     * @param  array<string, mixed>  $feedConfig
     */
    private function buildQuery(array $feedConfig): string
    {
        $base = isset($feedConfig['query']) ? trim((string) $feedConfig['query']) : '';

        if ($base === '') {
            /** @var list<string> $terms */
            $terms = array_values(array_filter(array_map(
                fn ($t) => trim((string) $t),
                (array) ($feedConfig['keywords'] ?? $feedConfig['topics'] ?? []),
            ), fn (string $t) => $t !== ''));

            $base = $this->orGroup($terms);
        }

        if ($base === '') {
            throw new NewsSourceException('GDELT query is empty — provide a query, keywords, or topics.');
        }

        $filters = [$base];

        $lang = $feedConfig['sourcelang'] ?? $feedConfig['language'] ?? null;
        if (is_string($lang) && $lang !== '') {
            $filters[] = 'sourcelang:'.$lang;
        }

        $country = $feedConfig['sourcecountry'] ?? $feedConfig['country'] ?? null;
        if (is_string($country) && $country !== '') {
            $filters[] = 'sourcecountry:'.$country;
        }

        $domains = array_values(array_filter(array_map(
            fn ($d) => trim((string) $d),
            (array) ($feedConfig['domains'] ?? []),
        ), fn (string $d) => $d !== ''));
        if ($domains !== []) {
            $filters[] = $this->orGroup(array_map(fn (string $d) => 'domainis:'.$d, $domains), quote: false);
        }

        return implode(' ', $filters);
    }

    /**
     * Quote multi-word phrases and OR-join a term group, e.g.
     * `("water heater repair" OR tankless)`.
     *
     * @param  list<string>  $terms
     */
    private function orGroup(array $terms, bool $quote = true): string
    {
        if ($terms === []) {
            return '';
        }

        $rendered = array_map(function (string $term) use ($quote): string {
            return $quote && str_contains($term, ' ') ? '"'.$term.'"' : $term;
        }, $terms);

        return count($rendered) === 1 ? $rendered[0] : '('.implode(' OR ', $rendered).')';
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
