<?php

namespace App\ContentEngine\Review;

/**
 * The outcome of an approve attempt. A required-image render failure blocks
 * approval outright; an unsupported claim only warns (the operator decides).
 */
final class ApproveResult
{
    /**
     * @param  list<string>  $warnings
     */
    private function __construct(
        public readonly bool $approved,
        public readonly bool $dispatched,
        public readonly ?string $blockedReason,
        public readonly array $warnings,
        public readonly ?int $queuePosition = null,
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function approved(array $warnings = []): self
    {
        return new self(true, true, null, $warnings);
    }

    public static function blocked(string $reason): self
    {
        return new self(false, false, $reason, []);
    }

    /**
     * Accepted but NOT pushed: the publish drip queued it (§ Publish drip) — it goes live when the
     * earlier batch is indexed. `$position` is its place in the site's queue.
     *
     * @param  list<string>  $warnings
     */
    public static function queued(int $position, array $warnings = []): self
    {
        return new self(true, false, null, $warnings, $position);
    }

    public function isQueued(): bool
    {
        return $this->queuePosition !== null;
    }

    public function isBlocked(): bool
    {
        return $this->blockedReason !== null;
    }
}
