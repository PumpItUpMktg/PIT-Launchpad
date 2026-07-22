<?php

namespace App\Styling;

/**
 * The brand style variations of the Gutenberg pivot. Each maps 1:1 to a block-theme `theme.json`
 * style variation (`/styles/{slug}.json`) and carries a FULL six-role palette — base, surface, text,
 * primary, highlight, and button — not just a two-color brand pair, so the variations differ in their
 * whole look (ground, ink, and CTA), not only the accent. The control plane recommends one (logo-first,
 * then the voice/AI pick), the operator can override, and the chosen variation's tokens render the site.
 *
 * This enum is the SINGLE SOURCE OF TRUTH for the palettes: the theme's `/styles/*.json` files are
 * generated from `palette()` (see `launchpad:build-theme-variations`), so a color only ever changes in
 * one place. The block PATTERNS are style-agnostic and inherit whichever variation is active, so
 * switching the variation restyles every page without regeneration.
 */
enum StyleVariation: string
{
    case Clean = 'clean';
    case Bold = 'bold';
    case Warm = 'warm';
    case Fresh = 'fresh';
    case Premium = 'premium';
    case Forest = 'forest';
    case Slate = 'slate';
    case Coastal = 'coastal';
    case Crimson = 'crimson';
    case Midnight = 'midnight';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Clean & Trustworthy',
            self::Bold => 'Bold & Direct',
            self::Warm => 'Warm & Local',
            self::Fresh => 'Fresh & Modern',
            self::Premium => 'Deep & Premium',
            self::Forest => 'Forest & Grounded',
            self::Slate => 'Slate & Signal',
            self::Coastal => 'Coastal & Calm',
            self::Crimson => 'Crimson & Confident',
            self::Midnight => 'Midnight & Electric',
        };
    }

    /** A one-line description of who the variation fits — surfaced on the recommendation screen. */
    public function blurb(): string
    {
        return match ($this) {
            self::Clean => 'Calm, precise and premium — the trustworthy default for careful, credentialed brands.',
            self::Bold => 'High-contrast charcoal with a decisive orange-red — for direct, results-first brands.',
            self::Warm => 'Earthy amber and clay — for local, relationship-led brands close to their community.',
            self::Fresh => 'Clean teal and mint — current and approachable, for a modern, upfront brand.',
            self::Premium => 'A dark ground with brushed gold — upscale and quiet, for a high-end operator.',
            self::Forest => 'Forest green and stone — steady and established, rooted in craft.',
            self::Slate => 'Cool grey with a vivid safety-orange signal — utilitarian and dependable.',
            self::Coastal => 'Soft teal-blue and warm sand — easygoing and friendly.',
            self::Crimson => 'Deep professional red — assertive and memorable.',
            self::Midnight => 'Dark navy with electric cyan — modern and technical.',
        };
    }

    /** Is this a dark-ground variation? The picker preview + on-* text resolution differ for a dark base. */
    public function isDark(): bool
    {
        return in_array($this, [self::Premium, self::Midnight], true);
    }

    /**
     * The variation's full six-role palette plus the derived neutrals the theme.json needs. `base`,
     * `surface`, `text`, `primary`, `highlight` and `button` are the roles shown in the picker; `muted`,
     * `border`, `on_accent` and `on_button` are derived contrast/neutral tokens.
     *
     * @return array{
     *     base: string, surface: string, text: string, muted: string, border: string,
     *     primary: string, highlight: string, on_accent: string, button: string, on_button: string
     * }
     */
    public function palette(): array
    {
        return match ($this) {
            self::Clean => [
                'base' => '#ffffff', 'surface' => '#f1f5f9', 'text' => '#0f172a', 'muted' => '#475569', 'border' => '#e2e8f0',
                'primary' => '#123B6B', 'highlight' => '#1D6FD6', 'on_accent' => '#ffffff', 'button' => '#1D6FD6', 'on_button' => '#ffffff',
            ],
            self::Bold => [
                'base' => '#ffffff', 'surface' => '#f5f3f2', 'text' => '#1a1a1a', 'muted' => '#57534e', 'border' => '#e7e5e4',
                'primary' => '#111827', 'highlight' => '#E4572E', 'on_accent' => '#ffffff', 'button' => '#E4572E', 'on_button' => '#ffffff',
            ],
            self::Warm => [
                'base' => '#fffdf8', 'surface' => '#f6efe3', 'text' => '#2b2620', 'muted' => '#6b5d4f', 'border' => '#e7dcc9',
                'primary' => '#7C4A24', 'highlight' => '#E08D3C', 'on_accent' => '#ffffff', 'button' => '#C9702A', 'on_button' => '#ffffff',
            ],
            self::Fresh => [
                'base' => '#ffffff', 'surface' => '#eefaf6', 'text' => '#0f2a26', 'muted' => '#4b6b64', 'border' => '#d5eae4',
                'primary' => '#0B5D52', 'highlight' => '#14B8A6', 'on_accent' => '#ffffff', 'button' => '#0EA5A0', 'on_button' => '#ffffff',
            ],
            self::Premium => [
                'base' => '#0f1620', 'surface' => '#17202e', 'text' => '#e8edf4', 'muted' => '#9aa7bc', 'border' => '#263241',
                'primary' => '#D4AF37', 'highlight' => '#E7C55A', 'on_accent' => '#1a1206', 'button' => '#C9A227', 'on_button' => '#14100a',
            ],
            self::Forest => [
                'base' => '#ffffff', 'surface' => '#f0f4ef', 'text' => '#1c2b22', 'muted' => '#52645a', 'border' => '#dce6da',
                'primary' => '#1E5233', 'highlight' => '#4C9A2A', 'on_accent' => '#ffffff', 'button' => '#3E7D2B', 'on_button' => '#ffffff',
            ],
            self::Slate => [
                'base' => '#ffffff', 'surface' => '#f2f4f7', 'text' => '#1f2937', 'muted' => '#64748b', 'border' => '#e2e8f0',
                'primary' => '#334155', 'highlight' => '#F97316', 'on_accent' => '#ffffff', 'button' => '#F97316', 'on_button' => '#ffffff',
            ],
            self::Coastal => [
                'base' => '#fbfeff', 'surface' => '#eaf4f7', 'text' => '#14343d', 'muted' => '#4e6c74', 'border' => '#d3e6ea',
                'primary' => '#226C82', 'highlight' => '#E0A458', 'on_accent' => '#14343d', 'button' => '#226C82', 'on_button' => '#ffffff',
            ],
            self::Crimson => [
                'base' => '#ffffff', 'surface' => '#f7f2f2', 'text' => '#1a1414', 'muted' => '#6b5555', 'border' => '#ecdcdc',
                'primary' => '#8C1D2C', 'highlight' => '#C8102E', 'on_accent' => '#ffffff', 'button' => '#C8102E', 'on_button' => '#ffffff',
            ],
            self::Midnight => [
                'base' => '#0b1220', 'surface' => '#131c2e', 'text' => '#eaf1fb', 'muted' => '#93a4be', 'border' => '#22304a',
                'primary' => '#4D97E8', 'highlight' => '#38BDF8', 'on_accent' => '#06131f', 'button' => '#2F86E0', 'on_button' => '#ffffff',
            ],
        };
    }

    /**
     * The variation's typography + shape tokens — the heading typeface (one of the three bundled with
     * the theme: Archivo / Manrope / Bricolage Grotesque), its weight, the corner radius, and heading
     * tracking.
     *
     * @return array{heading_font: string, heading_weight: int, radius: string, heading_letter_spacing: string}
     */
    public function typography(): array
    {
        return match ($this) {
            self::Clean => ['heading_font' => 'Manrope', 'heading_weight' => 700, 'radius' => '12px', 'heading_letter_spacing' => '0em'],
            self::Bold => ['heading_font' => 'Archivo', 'heading_weight' => 800, 'radius' => '3px', 'heading_letter_spacing' => '-0.02em'],
            self::Warm => ['heading_font' => 'Bricolage Grotesque', 'heading_weight' => 700, 'radius' => '18px', 'heading_letter_spacing' => '0em'],
            self::Fresh => ['heading_font' => 'Manrope', 'heading_weight' => 700, 'radius' => '14px', 'heading_letter_spacing' => '-0.01em'],
            self::Premium => ['heading_font' => 'Archivo', 'heading_weight' => 800, 'radius' => '6px', 'heading_letter_spacing' => '-0.01em'],
            self::Forest => ['heading_font' => 'Bricolage Grotesque', 'heading_weight' => 700, 'radius' => '14px', 'heading_letter_spacing' => '0em'],
            self::Slate => ['heading_font' => 'Manrope', 'heading_weight' => 700, 'radius' => '8px', 'heading_letter_spacing' => '-0.01em'],
            self::Coastal => ['heading_font' => 'Bricolage Grotesque', 'heading_weight' => 700, 'radius' => '16px', 'heading_letter_spacing' => '0em'],
            self::Crimson => ['heading_font' => 'Archivo', 'heading_weight' => 800, 'radius' => '4px', 'heading_letter_spacing' => '-0.01em'],
            self::Midnight => ['heading_font' => 'Manrope', 'heading_weight' => 700, 'radius' => '10px', 'heading_letter_spacing' => '-0.01em'],
        };
    }

    /**
     * The two-color brand pair + typography the older consumers read (activeLook, ProofEditor, the
     * theme.json Layer-1 emitter). Kept for back-compat: `primary`/`accent` come from the full palette.
     *
     * @return array{
     *     primary: string, accent: string, heading_font: string,
     *     heading_weight: int, radius: string, heading_letter_spacing: string
     * }
     */
    public function tokens(): array
    {
        $p = $this->palette();
        $t = $this->typography();

        return [
            'primary' => $p['primary'],
            'accent' => $p['highlight'],
            'heading_font' => $t['heading_font'],
            'heading_weight' => $t['heading_weight'],
            'radius' => $t['radius'],
            'heading_letter_spacing' => $t['heading_letter_spacing'],
        ];
    }

    /** The `theme.json` style-variation file slug (`/styles/{slug}.json`). */
    public function themeVariationSlug(): string
    {
        return $this->value;
    }

    /**
     * The other variations (the "or try these" alternates on the recommendation screen), in a stable
     * order (enum declaration order).
     *
     * @return list<self>
     */
    public function alternates(): array
    {
        return array_values(array_filter(self::cases(), fn (self $v): bool => $v !== $this));
    }
}
