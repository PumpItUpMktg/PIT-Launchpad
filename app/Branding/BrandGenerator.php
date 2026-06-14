<?php

namespace App\Branding;

use App\Integrations\Claude\ClaudeClient;

/**
 * Generates a brand (palette + typography + rationale) from a short interview, for
 * the ~99% of clients with no existing brand kit. The prompt enforces real design
 * discipline — color theory (primary/accent contrast, a readable neutral text
 * color, WCAG-AA, industry-appropriateness), typographic pairing, and voice-driven
 * choices from the personality answer.
 *
 * CRITICAL guard: the model's chosen fonts are validated against the real loadable
 * Google Fonts catalog, and any miss/hallucination/misspelling falls back to a safe
 * default — because an unavailable family would silently break the Global Kit
 * cascade. The text color is likewise held to a WCAG-AA contrast floor. Every such
 * correction is recorded on the result so the review screen (and tests) can see it.
 *
 * Output is the {palette:{primary,accent,text}, typography:{heading,body}, rationale}
 * shape that maps straight onto SiteBranding and the BrandKitAssembler.
 */
class BrandGenerator
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly FontCatalog $fonts,
    ) {}

    public function generate(BrandBrief $brief): GeneratedBrand
    {
        $raw = $this->parse($this->claude->complete($this->prompt($brief), $this->system()));

        $adjustments = [];

        $palette = $this->validatePalette(is_array($raw['palette'] ?? null) ? $raw['palette'] : [], $adjustments);
        $typography = $this->validateTypography(is_array($raw['typography'] ?? null) ? $raw['typography'] : [], $adjustments);
        $rationale = trim((string) ($raw['rationale'] ?? ''));

        return new GeneratedBrand($palette, $typography, $rationale, $adjustments);
    }

    private function system(): string
    {
        return 'You are a senior brand designer for local service businesses. You apply real color '
            .'theory and typographic pairing principles, and you choose with intent — the brand must '
            .'express the requested personality. You return STRICT JSON only, never prose or code fences.';
    }

    private function prompt(BrandBrief $brief): string
    {
        $personality = BrandBrief::PERSONALITIES[$brief->personality] ?? $brief->personality;

        $lines = [
            "Design a brand for a {$brief->industry} business.",
            "Brand personality: {$personality}.",
        ];

        if ($brief->emotionalGoal !== '') {
            $lines[] = "It should make a visitor feel: {$brief->emotionalGoal}.";
        }
        if ($brief->colorAnchorsUse !== []) {
            $lines[] = 'Prefer these colors if appropriate: '.implode(', ', $brief->colorAnchorsUse).'.';
        }
        if ($brief->colorAnchorsAvoid !== []) {
            $lines[] = 'Avoid these colors: '.implode(', ', $brief->colorAnchorsAvoid).'.';
        }
        if ($brief->admiredBrand !== '') {
            $lines[] = "The client admires the feel of: {$brief->admiredBrand} (take inspiration, do not copy).";
        }

        $lines[] = '';
        $lines[] = 'Requirements:';
        $lines[] = '- COLOR: a primary, an accent with clear contrast against the primary, and a dark '
            .'neutral text color that meets WCAG-AA (>= 4.5:1) on a light background. Colors must suit '
            .'the industry and personality. Use 6-digit hex (#RRGGBB).';
        $lines[] = '- TYPOGRAPHY: a professional heading + body pairing that follows real pairing '
            .'principles and expresses the personality. Use ONLY real, widely-available Google Fonts '
            .'families, spelled exactly as Google Fonts lists them (e.g. "Playfair Display", "Inter").';
        $lines[] = '- RATIONALE: 2-4 sentences explaining why these colors and fonts fit the industry '
            .'and personality (this is shown to the client).';
        $lines[] = '';
        $lines[] = 'Respond with ONLY this JSON: '
            .'{"palette":{"primary":"#RRGGBB","accent":"#RRGGBB","text":"#RRGGBB"},'
            .'"typography":{"heading":"Family Name","body":"Family Name"},"rationale":"..."}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $palette
     * @param  list<string>  $adjustments  (by ref)
     * @return array{primary: string, accent: string, text: string}
     */
    private function validatePalette(array $palette, array &$adjustments): array
    {
        $safe = (array) config('launchpad.brand.safe_colors', []);

        $primary = $this->validateHex((string) ($palette['primary'] ?? ''))
            ?? $this->fallbackColor('primary', (string) ($safe['primary'] ?? '#0F62FE'), $adjustments);
        $accent = $this->validateHex((string) ($palette['accent'] ?? ''))
            ?? $this->fallbackColor('accent', (string) ($safe['accent'] ?? '#FF6F00'), $adjustments);
        $text = $this->validateHex((string) ($palette['text'] ?? ''))
            ?? $this->fallbackColor('text', (string) ($safe['text'] ?? '#1A1A1A'), $adjustments);

        // WCAG-AA guard: text must be readable on a light background, else the
        // cascade renders unreadable body copy. Correct to the safe neutral.
        $minContrast = (float) config('launchpad.brand.min_text_contrast', 4.5);
        if ($this->contrast($text, '#FFFFFF') < $minContrast) {
            $safeText = (string) ($safe['text'] ?? '#1A1A1A');
            $normalizedSafe = $this->validateHex($safeText) ?? '#1a1a1a';
            if ($text !== $normalizedSafe) {
                $adjustments[] = "Text color {$text} failed WCAG-AA on a light background — corrected to {$safeText}.";
                $text = $normalizedSafe;
            }
        }

        return ['primary' => $primary, 'accent' => $accent, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $typography
     * @param  list<string>  $adjustments  (by ref)
     * @return array{heading: string, body: string}
     */
    private function validateTypography(array $typography, array &$adjustments): array
    {
        return [
            'heading' => $this->validateFont('heading', (string) ($typography['heading'] ?? ''), $adjustments),
            'body' => $this->validateFont('body', (string) ($typography['body'] ?? ''), $adjustments),
        ];
    }

    /**
     * The font guard: resolve the family to its canonical Google Fonts spelling, or
     * fall back to the configured safe default and record why — so an invented or
     * misspelled family never reaches (and breaks) the Global Kit.
     *
     * @param  list<string>  $adjustments  (by ref)
     */
    private function validateFont(string $role, string $family, array &$adjustments): string
    {
        $canonical = $this->fonts->canonical($family);
        if ($canonical !== null) {
            return $canonical;
        }

        $safe = (string) config("launchpad.brand.safe_fonts.{$role}", $role === 'heading' ? 'Poppins' : 'Inter');
        $shown = trim($family) === '' ? '(none returned)' : "\"{$family}\"";
        $adjustments[] = "{$role} font {$shown} is not a loadable Google Font — fell back to {$safe}.";

        return $safe;
    }

    /**
     * @param  list<string>  $adjustments  (by ref)
     */
    private function fallbackColor(string $role, string $safe, array &$adjustments): string
    {
        $adjustments[] = "Invalid or missing {$role} color — fell back to {$safe}.";

        return $this->validateHex($safe) ?? '#1a1a1a';
    }

    /** Normalize a hex string to #RRGGBB, or null when it is not a valid hex color. */
    private function validateHex(string $hex): ?string
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }

        return '#'.strtolower($hex);
    }

    /** WCAG contrast ratio between two #RRGGBB colors (1–21). */
    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Tolerant JSON parse: take the outermost {...}, fence/prose tolerant.
     *
     * @return array<string, mixed>
     */
    private function parse(string $response): array
    {
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
