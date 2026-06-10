<?php

namespace App\ContentEngine\Feeds;

use App\ContentEngine\FunnelResult;

/**
 * The per-feed ingest verdict, broken into the funnel's stages so a 0-candidates
 * outcome is legible: where did the items go — dropped at the cheap pre-filter
 * (empty/junk), merged away as same-story duplicates, rejected by the scorer
 * (brand-safety / off-silo / below threshold), parked borderline, or routed to a
 * candidate. Recency is a backfill-only stage, so it is not counted here (the
 * steady-state ingest scores whatever the feed currently serves).
 */
final class FeedIngestReport
{
    public function __construct(
        public readonly string $feedId,
        public readonly string $label,
        public readonly int $fetched,
        public readonly int $prefilteredOut,
        public readonly int $deduped,
        public readonly int $scoreRejected,
        public readonly int $routed,
        public readonly int $parked,
        public readonly int $refreshMarked,
        public readonly ?string $error = null,
    ) {}

    public static function unfetched(string $feedId, string $label, ?string $error): self
    {
        return new self($feedId, $label, 0, 0, 0, 0, 0, 0, 0, $error);
    }

    public static function fromFunnel(string $feedId, string $label, int $fetched, FunnelResult $funnel): self
    {
        $prefilteredOut = count(array_filter($funnel->dropped, fn (array $d) => $d['reason'] === 'pre_filter'));
        $scoreRejected = count($funnel->dropped) - $prefilteredOut;
        $routed = count($funnel->created);
        $parked = count($funnel->parked);
        $refreshMarked = count($funnel->refreshMarked);

        // Same-story clustering collapses survivors before scoring; what was merged
        // away (never individually scored) is the deduped count.
        $deduped = max(0, ($fetched - $prefilteredOut) - ($routed + $parked + $refreshMarked + $scoreRejected));

        return new self($feedId, $label, $fetched, $prefilteredOut, $deduped, $scoreRejected, $routed, $parked, $refreshMarked);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toLog(): array
    {
        return [
            'feed_id' => $this->feedId,
            'label' => $this->label,
            'fetched' => $this->fetched,
            'prefiltered_out' => $this->prefilteredOut,
            'deduped' => $this->deduped,
            'score_rejected' => $this->scoreRejected,
            'routed' => $this->routed,
            'parked' => $this->parked,
            'refresh_marked' => $this->refreshMarked,
            'error' => $this->error,
        ];
    }

    public function line(): string
    {
        if ($this->error !== null) {
            return "unfetched — {$this->error}";
        }

        return sprintf(
            'fetched %d → prefiltered-out %d → deduped %d → score-rejected %d → routed %d (parked %d, refresh %d)',
            $this->fetched, $this->prefilteredOut, $this->deduped, $this->scoreRejected, $this->routed, $this->parked, $this->refreshMarked,
        );
    }
}
