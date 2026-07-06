<?php

namespace App\Styling;

/**
 * The three brand style variations of the Gutenberg pivot — the exact token sets the
 * style-recommendation mockup specifies. Each maps 1:1 to a block-theme `theme.json` style
 * variation (`/styles/{slug}.json`); the control plane recommends one, the operator can override,
 * and the chosen variation's tokens are written to the site's `theme.json` (the SINGLE
 * brand-styling surface — there is no Elementor Global Kit push in this world).
 *
 * Tokens are the brand-styling contract; the block PATTERNS (Layer 2) are style-agnostic and inherit
 * whichever variation is active, so switching the variation restyles every page without regeneration.
 */
enum StyleVariation: string
{
    case Bold = 'bold';
    case Clean = 'clean';
    case Warm = 'warm';

    public function label(): string
    {
        return match ($this) {
            self::Bold => 'Bold & Direct',
            self::Clean => 'Clean & Trustworthy',
            self::Warm => 'Warm & Local',
        };
    }

    /** A one-line description of who the variation fits — surfaced on the recommendation screen. */
    public function blurb(): string
    {
        return match ($this) {
            self::Bold => 'High-contrast and confident — for direct, results-first and commercial brands.',
            self::Clean => 'Calm, precise and premium — the trustworthy default for careful, credentialed brands.',
            self::Warm => 'Approachable and rooted — for local, relationship-led brands close to their community.',
        };
    }

    /**
     * The variation's design tokens — the shape `theme.json` emission (Layer 1) consumes directly:
     * a two-color brand palette, the heading typeface + weight, corner radius, and heading tracking.
     *
     * @return array{
     *     primary: string,
     *     accent: string,
     *     heading_font: string,
     *     heading_weight: int,
     *     radius: string,
     *     heading_letter_spacing: string
     * }
     */
    public function tokens(): array
    {
        return match ($this) {
            self::Bold => [
                'primary' => '#0B1F33',           // navy
                'accent' => '#EA580C',            // orange
                'heading_font' => 'Archivo',
                'heading_weight' => 800,
                'radius' => '3px',
                'heading_letter_spacing' => '-0.02em', // tight tracking
            ],
            self::Clean => [
                'primary' => '#123B6B',           // deep blue
                'accent' => '#1D6FD6',
                'heading_font' => 'Manrope',
                'heading_weight' => 700,
                'radius' => '12px',
                'heading_letter_spacing' => '0em',     // airy
            ],
            self::Warm => [
                'primary' => '#14513F',           // pine
                'accent' => '#DD8A2B',            // amber
                'heading_font' => 'Bricolage Grotesque',
                'heading_weight' => 700,
                'radius' => '18px',
                'heading_letter_spacing' => '0em',
            ],
        };
    }

    /** The `theme.json` style-variation file slug (`/styles/{slug}.json`). */
    public function themeVariationSlug(): string
    {
        return $this->value;
    }

    /**
     * The other two variations (the "or try these" alternates on the recommendation screen),
     * in a stable order.
     *
     * @return list<self>
     */
    public function alternates(): array
    {
        return array_values(array_filter(self::cases(), fn (self $v): bool => $v !== $this));
    }
}
