<?php

namespace App\Support\Ui;

/**
 * The ONE chip color vocabulary. Every state chip across the admin resolves to exactly one of
 * these five tones — there is no per-page color choice. The tone is the semantic (what the state
 * MEANS to the operator), never the literal state name, so the palette stays small and legible:
 *
 *  - Good    — a settled, healthy end-state (published, live, approved).
 *  - Warn    — needs a human (needs review, onboarding, an advisory flag).
 *  - Bad     — a failure that blocks (render/publish failed, suspended, brand-safety).
 *  - Info    — in-flight / neutral-progress (drafting, rendering, active, candidate).
 *  - Neutral — inert / dismissed (rejected, archived).
 */
enum ChipTone: string
{
    case Good = 'good';
    case Warn = 'warn';
    case Bad = 'bad';
    case Info = 'info';
    case Neutral = 'neutral';

    /** The CSS modifier class suffix ({@see resources/views/components/lp/chip.blade.php}). */
    public function cssClass(): string
    {
        return 'lp-chip--'.$this->value;
    }
}
