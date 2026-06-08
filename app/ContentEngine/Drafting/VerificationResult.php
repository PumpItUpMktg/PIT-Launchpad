<?php

namespace App\ContentEngine\Drafting;

/**
 * The post-draft accuracy audit. Every business assertion must trace to the
 * Claims pool; unsupported ones are flagged (never silently dropped) so a human
 * resolves them in review. Sources are recorded as attributions, with the
 * citation-URL policy already applied. A draft with unsupported claims still
 * ships to review — it ships flagged.
 */
final class VerificationResult
{
    /**
     * @param  list<array{text: string, claim_id: string|null}>  $supportedClaims
     * @param  list<array{text: string, claim_id: string|null}>  $unsupportedClaims
     * @param  list<array{name: string, url: string|null}>  $sourceAttributions
     */
    public function __construct(
        public readonly array $supportedClaims,
        public readonly array $unsupportedClaims,
        public readonly array $sourceAttributions,
    ) {}

    public function passed(): bool
    {
        return $this->unsupportedClaims === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'supported_claims' => $this->supportedClaims,
            'unsupported_claims' => $this->unsupportedClaims,
            'source_attributions' => $this->sourceAttributions,
        ];
    }
}
