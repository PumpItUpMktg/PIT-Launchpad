<?php

namespace App\Branding;

/**
 * The 3–5 validated candidates the generator surfaces, plus the structure they were
 * generated for. Exactly one candidate is `recommended` (best industry-fit +
 * personality-match + accessibility); the rest are alternates. Never empty — the
 * generator synthesizes a guaranteed-accessible safe candidate if every model
 * candidate is dropped by the gates.
 */
final class BrandCandidateSet
{
    /**
     * @param  list<BrandCandidate>  $candidates
     */
    public function __construct(
        public readonly array $candidates,
        public readonly string $structure,
        public readonly Scheme $scheme = Scheme::Light,
    ) {}

    public function recommended(): ?BrandCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->recommended) {
                return $candidate;
            }
        }

        return $this->candidates[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    /**
     * @return array{structure: string, scheme: string, candidates: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'structure' => $this->structure,
            'scheme' => $this->scheme->value,
            'candidates' => array_map(fn (BrandCandidate $c) => $c->toArray(), $this->candidates),
        ];
    }
}
