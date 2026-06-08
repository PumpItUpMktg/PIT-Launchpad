<?php

namespace App\SiloCreator;

/**
 * A silo's topical boundary: seed terms plus include/exclude patterns. Seeded
 * here from service scope + customer problems (and theme terms for topical
 * silos); §5 later refines it with SERP signal. Stored on Silo.rule_set.
 */
final class RuleSet
{
    /**
     * @param  list<string>  $seedTerms
     * @param  list<string>  $includePatterns
     * @param  list<string>  $excludePatterns
     */
    public function __construct(
        public readonly array $seedTerms = [],
        public readonly array $includePatterns = [],
        public readonly array $excludePatterns = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->seedTerms === [] && $this->includePatterns === [] && $this->excludePatterns === [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return [
            'seed_terms' => $this->seedTerms,
            'include_patterns' => $this->includePatterns,
            'exclude_patterns' => $this->excludePatterns,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            seedTerms: array_values(array_map('strval', $data['seed_terms'] ?? [])),
            includePatterns: array_values(array_map('strval', $data['include_patterns'] ?? [])),
            excludePatterns: array_values(array_map('strval', $data['exclude_patterns'] ?? [])),
        );
    }

    /**
     * All textual content, for geo-neutral scanning.
     */
    public function allTerms(): string
    {
        return implode(' ', [...$this->seedTerms, ...$this->includePatterns, ...$this->excludePatterns]);
    }
}
