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
