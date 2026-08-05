<?php

namespace App\Integrations\Serp;

/**
 * The normalized organic SERP for a query.
 */
final class SerpResultSet
{
    /**
     * @param  list<SerpResult>  $results
     */
    public function __construct(
        public readonly string $query,
        public readonly array $results = [],
    ) {}

    /**
     * @return list<string>
     */
    public function domains(): array
    {
        return array_map(fn (SerpResult $r) => $r->domain, $this->results);
    }

    /**
     * Deploy-safe cache shape: plain primitives only, never the value object.
     *
     * Value objects serialized into the shared cache deserialize to a
     * `__PHP_Incomplete_Class` in any process whose class definition is stale
     * (an FPM/queue worker running pre-deploy code) — which silently breaks the
     * standard-mode read → snapshot loop. Caching the array round-trips cleanly
     * across every process and deploy boundary. {@see self::fromArray()}.
     *
     * @return array{query: string, results: list<array{position: int, url: string, domain: string}>}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'results' => array_map(
                fn (SerpResult $r): array => ['position' => $r->position, 'url' => $r->url, 'domain' => $r->domain],
                $this->results,
            ),
        ];
    }

    /**
     * Rebuild from the {@see self::toArray()} cache shape. Tolerant of a missing
     * or malformed payload (returns an empty set) so a poisoned/legacy entry can
     * never fatal the read path.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = is_array($data['results'] ?? null) ? $data['results'] : [];

        $results = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $results[] = new SerpResult((int) ($r['position'] ?? 0), (string) ($r['url'] ?? ''), (string) ($r['domain'] ?? ''));
        }

        return new self((string) ($data['query'] ?? ''), $results);
    }

    /**
     * Our own ranking results (used for cannibalization detection).
     *
     * @return list<SerpResult>
     */
    public function ownedBy(string $domain): array
    {
        $domain = strtolower($domain);

        return array_values(array_filter($this->results, fn (SerpResult $r) => strtolower($r->domain) === $domain));
    }
}
