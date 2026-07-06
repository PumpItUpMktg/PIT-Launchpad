<?php

namespace App\Publishing\Blocks;

/**
 * The cross-cutting page inputs the block sections read: the click-to-call phone and the
 * emergency data-gate. Emergency ON makes the phone the PRIMARY call-to-action with urgent framing
 * (the hero "Call" button leads, a "24/7" trust stat, a "call now" line in the CTA); OFF keeps the
 * number prominent but calm (the assessment button leads, no "24/7" claim). Off by default so a
 * non-emergency business never carries a false 24/7 claim.
 */
final class PageContext
{
    public function __construct(
        public readonly ?string $phoneDisplay,
        public readonly ?string $phoneTel,
        public readonly bool $emergency = false,
    ) {}

    public function hasPhone(): bool
    {
        return $this->phoneDisplay !== null && trim($this->phoneDisplay) !== ''
            && $this->phoneTel !== null && trim($this->phoneTel) !== '';
    }
}
