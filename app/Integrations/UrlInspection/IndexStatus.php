<?php

namespace App\Integrations\UrlInspection;

use App\Enums\IndexCoverageState;
use Illuminate\Support\Carbon;

/**
 * The normalized result of one Google Search Console URL Inspection — the authoritative per-URL index
 * signal. `state` is the coarse verdict ({@see IndexCoverageState}); `coverageState` keeps Google's exact
 * human phrase for display; `googleCanonical` is the canonical Google actually chose (want it to equal the
 * inspected URL for a page that should be the canonical, e.g. `/hoboken-nj`).
 */
final class IndexStatus
{
    public function __construct(
        public readonly string $url,
        public readonly IndexCoverageState $state,
        public readonly string $coverageState,
        public readonly ?string $verdict = null,
        public readonly ?string $googleCanonical = null,
        public readonly ?string $userCanonical = null,
        public readonly ?Carbon $lastCrawledAt = null,
    ) {}

    public function indexed(): bool
    {
        return $this->state->indexed();
    }

    /** True when Google picked a DIFFERENT canonical than this URL — a signal worth surfacing. */
    public function canonicalMismatch(): bool
    {
        $g = trim((string) $this->googleCanonical);

        return $g !== '' && rtrim($g, '/') !== rtrim($this->url, '/');
    }

    /**
     * @return array{url: string, state: string, label: string, indexed: bool, coverage_state: string, verdict: ?string, google_canonical: ?string, user_canonical: ?string, canonical_mismatch: bool, last_crawled_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'verdict' => $this->verdict,
            'user_canonical' => $this->userCanonical,
            'state' => $this->state->value,
            'label' => $this->state->label(),
            'indexed' => $this->indexed(),
            'coverage_state' => $this->coverageState,
            'google_canonical' => $this->googleCanonical,
            'canonical_mismatch' => $this->canonicalMismatch(),
            'last_crawled_at' => $this->lastCrawledAt?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            state: IndexCoverageState::tryFrom((string) ($data['state'] ?? '')) ?? IndexCoverageState::Unknown,
            coverageState: (string) ($data['coverage_state'] ?? ''),
            verdict: isset($data['verdict']) ? (string) $data['verdict'] : null,
            googleCanonical: isset($data['google_canonical']) ? (string) $data['google_canonical'] : null,
            userCanonical: isset($data['user_canonical']) ? (string) $data['user_canonical'] : null,
            lastCrawledAt: isset($data['last_crawled_at']) && is_string($data['last_crawled_at']) && $data['last_crawled_at'] !== ''
                ? Carbon::parse($data['last_crawled_at'])
                : null,
        );
    }
}
