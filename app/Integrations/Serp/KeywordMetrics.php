<?php

namespace App\Integrations\Serp;

/**
 * Normalized keyword metrics — the vendor-agnostic contract every SERP/keyword
 * provider maps its raw output into.
 */
final class KeywordMetrics
{
    /**
     * @param  list<string>  $relatedTerms
     */
    public function __construct(
        public readonly string $query,
        public readonly int $volume,
        public readonly int $difficulty,
        public readonly array $relatedTerms = [],
    ) {}

    /**
     * Deploy-safe cache shape: plain primitives, never the value object — the
     * same reason as {@see SerpResultSet::toArray()} (a cached object deserializes
     * to `__PHP_Incomplete_Class` under stale code and breaks the read).
     *
     * @return array{query: string, volume: int, difficulty: int, relatedTerms: list<string>}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'volume' => $this->volume,
            'difficulty' => $this->difficulty,
            'relatedTerms' => $this->relatedTerms,
        ];
    }

    /**
     * Rebuild from the {@see self::toArray()} cache shape; tolerant of a
     * malformed payload so a poisoned/legacy entry can't fatal the read.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $related = is_array($data['relatedTerms'] ?? null) ? array_values(array_map('strval', $data['relatedTerms'])) : [];

        return new self((string) ($data['query'] ?? ''), (int) ($data['volume'] ?? 0), (int) ($data['difficulty'] ?? 0), $related);
    }
}
