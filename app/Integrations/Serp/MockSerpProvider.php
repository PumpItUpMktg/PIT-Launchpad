<?php

namespace App\Integrations\Serp;

/**
 * Deterministic, programmable SERP provider for tests and the default binding
 * (no vendor committed). Returns canned data where set, otherwise a stable
 * pseudo-value derived from the query.
 */
class MockSerpProvider implements SerpProvider
{
    /** @var array<string, KeywordMetrics> */
    private array $metrics = [];

    /** @var array<string, SerpResultSet> */
    private array $results = [];

    /**
     * @param  list<string>  $relatedTerms
     */
    public function setMetrics(string $query, int $volume, int $difficulty, array $relatedTerms = []): static
    {
        $this->metrics[$query] = new KeywordMetrics($query, $volume, $difficulty, $relatedTerms);

        return $this;
    }

    public function setResults(string $query, SerpResultSet $results): static
    {
        $this->results[$query] = $results;

        return $this;
    }

    public function metrics(string $query): KeywordMetrics
    {
        return $this->metrics[$query] ?? new KeywordMetrics(
            $query,
            volume: (crc32($query) % 9000) + 100,
            difficulty: crc32('d'.$query) % 101,
            relatedTerms: [],
        );
    }

    public function results(string $query): SerpResultSet
    {
        return $this->results[$query] ?? new SerpResultSet($query, []);
    }
}
