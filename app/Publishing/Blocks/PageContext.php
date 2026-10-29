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
        public readonly ?string $emergencyDisplay = null,
        public readonly ?string $emergencyTel = null,
        // A lead-capture form is configured for this page → the closing #contact CTA renders the
        // real form (the plugin's [lp_form] embed) instead of a button, so the page captures a lead
        // in place rather than bouncing a CTA to a dead anchor.
        public readonly bool $hasForm = false,
        // Where the form section sits when one is configured: 'hero' (a form card in the hero's right
        // column — home, office hubs, town pages), 'intro' (the service page's 60/40 description row,
        // composed by the spoke itself), or 'closing' (the soft-close CTA band — silo hubs, legacy).
        public readonly string $formPlacement = self::PLACEMENT_CLOSING,
    ) {}

    public const PLACEMENT_HERO = 'hero';

    public const PLACEMENT_INTRO = 'intro';

    public const PLACEMENT_CLOSING = 'closing';

    /** The hero renders the form card (and owns the #contact anchor). */
    public function formInHero(): bool
    {
        return $this->hasForm && $this->formPlacement === self::PLACEMENT_HERO;
    }

    /** The closing CTA band renders the form (and owns the #contact anchor). */
    public function formInClosing(): bool
    {
        return $this->hasForm && $this->formPlacement === self::PLACEMENT_CLOSING;
    }

    public function hasPhone(): bool
    {
        return $this->phoneDisplay !== null && trim($this->phoneDisplay) !== ''
            && $this->phoneTel !== null && trim($this->phoneTel) !== '';
    }

    /** The emergency/after-hours number to call — the dedicated line when set, else the main number. */
    public function emergencyCallDisplay(): ?string
    {
        return $this->emergencyDisplay !== null && trim($this->emergencyDisplay) !== ''
            ? $this->emergencyDisplay
            : $this->phoneDisplay;
    }

    public function emergencyCallTel(): ?string
    {
        return $this->emergencyTel !== null && trim($this->emergencyTel) !== ''
            ? $this->emergencyTel
            : $this->phoneTel;
    }
}
