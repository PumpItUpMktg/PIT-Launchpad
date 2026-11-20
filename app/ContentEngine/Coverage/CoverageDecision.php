<?php

namespace App\ContentEngine\Coverage;

use App\Enums\CoverageVerdict;

/**
 * The coverage verdict on one candidate, with the twin post and its Search Console standing behind it.
 * Stamped on the candidate's `meta.coverage` so the board reads it without recomputing.
 */
final class CoverageDecision
{
    public function __construct(
        public readonly CoverageVerdict $verdict,
        public readonly ?string $twinId,
        public readonly ?string $twinTitle,
        public readonly ?string $twinUrl,
        public readonly float $similarity,
        public readonly int $impressions,
        public readonly int $clicks,
        public readonly ?float $position,
        public readonly string $reason,
        public readonly ?string $checkedAt = null,
        /** What paired the candidate with the twin: title | angle | link; null for author. */
        public readonly ?string $matchedOn = null,
    ) {}

    public static function author(string $reason = 'No live post reads as this topic.'): self
    {
        return new self(CoverageVerdict::Author, null, null, null, 0.0, 0, 0, null, $reason);
    }

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'twin_id' => $this->twinId,
            'twin_title' => $this->twinTitle,
            'twin_url' => $this->twinUrl,
            'similarity' => $this->similarity,
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'position' => $this->position,
            'reason' => $this->reason,
            'matched_on' => $this->matchedOn,
            'checked_at' => $this->checkedAt ?? now()->toIso8601String(),
        ];
    }

    /** @param  mixed  $meta  the candidate's `meta.coverage`, whatever shape it is in */
    public static function fromMeta(mixed $meta): ?self
    {
        if (! is_array($meta) || ! isset($meta['verdict']) || ! is_string($meta['verdict'])) {
            return null;
        }
        $verdict = CoverageVerdict::tryFrom($meta['verdict']);
        if ($verdict === null) {
            return null;
        }

        return new self(
            $verdict,
            isset($meta['twin_id']) && is_string($meta['twin_id']) ? $meta['twin_id'] : null,
            isset($meta['twin_title']) && is_string($meta['twin_title']) ? $meta['twin_title'] : null,
            isset($meta['twin_url']) && is_string($meta['twin_url']) ? $meta['twin_url'] : null,
            (float) ($meta['similarity'] ?? 0),
            (int) ($meta['impressions'] ?? 0),
            (int) ($meta['clicks'] ?? 0),
            isset($meta['position']) && is_numeric($meta['position']) ? (float) $meta['position'] : null,
            (string) ($meta['reason'] ?? ''),
            isset($meta['checked_at']) && is_string($meta['checked_at']) ? $meta['checked_at'] : null,
            isset($meta['matched_on']) && is_string($meta['matched_on']) ? $meta['matched_on'] : null,
        );
    }
}
