<?php

namespace App\Branding;

use App\Styling\StyleVariation;

/**
 * Builds the per-tenant "Your brand colors" theme.json style variation from the logo's colors.
 *
 * The IRON LAW: the logo supplies **primary + accent only**. Everything else — neutrals (base / surface
 * / contrast / muted / border), the on-accent contrast color, the heading typeface, weight, corner
 * radius and tracking — is BORROWED WHOLESALE from the nearest curated variation (nearest by the logo's
 * tone: warm → Warm; cool-and-dark → Bold; cool-and-light → Clean). So the result is genuinely the
 * client's colors but a coherent, complete web palette — never a broken two-color guess.
 *
 * A monochrome logo (no usable accent) borrows the accent too. The output matches the theme's static
 * `styles/{slug}.json` shape exactly, so the companion plugin writes it to global styles the same way
 * it activates a curated variation. Neutrals + type are read from {@see StyleVariation} (the single
 * source of truth) so they can never drift from the theme files.
 */
final class BrandVariationBuilder
{
    public const SLUG = 'brand';

    public const TITLE = 'Your brand colors';

    /** The bundled heading fonts, keyed by name — the file + weight the variation file references. */
    private const FONTS = [
        'Archivo' => ['family' => 'Archivo, system-ui, sans-serif', 'file' => 'archivo-800.woff2', 'weight' => '800'],
        'Manrope' => ['family' => 'Manrope, system-ui, sans-serif', 'file' => 'manrope-700.woff2', 'weight' => '700'],
        'Bricolage Grotesque' => ['family' => '"Bricolage Grotesque", system-ui, sans-serif', 'file' => 'bricolage-grotesque-700.woff2', 'weight' => '700'],
    ];

    private const BODY_FAMILY = 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

    /**
     * The resolved palette colors (for storage / picker swatches) — the accent is the logo's when it
     * has two colors, else the borrowed curated accent.
     *
     * @return array{primary: string, accent: string, on_accent: string, base: string}
     */
    public function resolve(BrandColors $colors): array
    {
        $base = $this->nearestForColor($colors->primary);
        $accent = $this->normalize($colors->accent ?? StyleVariation::from($base)->palette()['highlight']);

        return [
            'primary' => $this->normalize($colors->primary),
            'accent' => $accent,
            'on_accent' => $this->onAccent($accent),
            'base' => $base,
        ];
    }

    /**
     * The full theme.json style-variation array (same shape as the theme's styles/{slug}.json). Neutrals
     * + typography come from the nearest curated {@see StyleVariation}; the logo supplies primary/accent.
     * The CTA button uses the logo accent (an on-brand button), so `button` = the resolved accent.
     *
     * @return array<string, mixed>
     */
    public function build(BrandColors $colors): array
    {
        $resolved = $this->resolve($colors);
        $variation = StyleVariation::from($resolved['base']);
        $n = $variation->palette();
        $t = $variation->typography();
        $font = self::FONTS[$t['heading_font']];

        $palette = [
            ['slug' => 'base', 'name' => 'Base', 'color' => $n['base']],
            ['slug' => 'surface', 'name' => 'Surface', 'color' => $n['surface']],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => $n['text']],
            ['slug' => 'muted', 'name' => 'Muted', 'color' => $n['muted']],
            ['slug' => 'border', 'name' => 'Border', 'color' => $n['border']],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => $resolved['primary']],
            ['slug' => 'accent', 'name' => 'Accent', 'color' => $resolved['accent']],
            ['slug' => 'on-accent', 'name' => 'On accent', 'color' => $resolved['on_accent']],
            ['slug' => 'button', 'name' => 'Button', 'color' => $resolved['accent']],
            ['slug' => 'on-button', 'name' => 'On button', 'color' => $resolved['on_accent']],
        ];

        return [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'title' => self::TITLE,
            'settings' => [
                'color' => ['palette' => $palette],
                'typography' => ['fontFamilies' => [
                    [
                        'slug' => 'heading',
                        'name' => $t['heading_font'],
                        'fontFamily' => $font['family'],
                        'fontFace' => [[
                            'fontFamily' => $t['heading_font'],
                            'fontWeight' => $font['weight'],
                            'fontStyle' => 'normal',
                            'fontDisplay' => 'swap',
                            'src' => ['file:./assets/fonts/'.$font['file']],
                        ]],
                    ],
                    [
                        'slug' => 'body',
                        'name' => 'Inter',
                        'fontFamily' => self::BODY_FAMILY,
                        'fontFace' => [
                            ['fontFamily' => 'Inter', 'fontWeight' => '400', 'fontStyle' => 'normal', 'fontDisplay' => 'swap', 'src' => ['file:./assets/fonts/inter-400.woff2']],
                            ['fontFamily' => 'Inter', 'fontWeight' => '600', 'fontStyle' => 'normal', 'fontDisplay' => 'swap', 'src' => ['file:./assets/fonts/inter-600.woff2']],
                        ],
                    ],
                ]],
                'custom' => [
                    'radius' => $t['radius'],
                    'headingLetterSpacing' => $t['heading_letter_spacing'],
                    'headingWeight' => (string) $t['heading_weight'],
                ],
            ],
        ];
    }

    /**
     * Nearest curated base by the logo primary's tone: warm hue → Warm; cool → Bold (dark) / Clean.
     * Returns a {@see StyleVariation} slug (the three graft bases the neutrals are borrowed from).
     */
    public function nearestForColor(string $hex): string
    {
        [$h, , $l] = $this->hsl($hex);

        $warm = ($h <= 50 || $h >= 335);
        if ($warm) {
            return 'warm';
        }

        return $l < 0.35 ? 'bold' : 'clean';
    }

    /** White on a dark accent, near-black on a light one — WCAG-ish contrast for button text. */
    private function onAccent(string $hex): string
    {
        [$r, $g, $b] = $this->rgb($hex);
        // Relative luminance (sRGB, simplified).
        $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $lum < 0.55 ? '#ffffff' : '#1f2937';
    }

    private function normalize(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.strtolower(substr($hex, 0, 6));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($this->normalize($hex), '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c): float => $c / 255, $this->rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h * 60, $s, $l];
    }
}
