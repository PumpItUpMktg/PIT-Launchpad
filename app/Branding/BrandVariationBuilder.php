<?php

namespace App\Branding;

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
 * it activates a curated variation.
 */
final class BrandVariationBuilder
{
    public const SLUG = 'brand';

    public const TITLE = 'Your brand colors';

    /** The curated bases the logo colors graft onto — neutrals + type, mirrored from the theme. */
    private const BASE = [
        'bold' => [
            'neutrals' => ['base' => '#ffffff', 'surface' => '#f2f5f8', 'contrast' => '#0B1F33', 'muted' => '#475569', 'border' => '#dbe3ea'],
            'accent' => '#EA580C',
            'heading' => ['name' => 'Archivo', 'family' => 'Archivo, system-ui, sans-serif', 'file' => 'archivo-800.woff2', 'weight' => '800'],
            'custom' => ['radius' => '3px', 'headingLetterSpacing' => '-0.02em', 'headingWeight' => '800'],
        ],
        'clean' => [
            'neutrals' => ['base' => '#ffffff', 'surface' => '#f1f5f9', 'contrast' => '#0f172a', 'muted' => '#475569', 'border' => '#e2e8f0'],
            'accent' => '#1D6FD6',
            'heading' => ['name' => 'Manrope', 'family' => 'Manrope, system-ui, sans-serif', 'file' => 'manrope-700.woff2', 'weight' => '700'],
            'custom' => ['radius' => '12px', 'headingLetterSpacing' => '0em', 'headingWeight' => '700'],
        ],
        'warm' => [
            'neutrals' => ['base' => '#ffffff', 'surface' => '#f4f1ea', 'contrast' => '#14261e', 'muted' => '#4b5a52', 'border' => '#e4ded1'],
            'accent' => '#DD8A2B',
            'heading' => ['name' => 'Bricolage Grotesque', 'family' => '"Bricolage Grotesque", system-ui, sans-serif', 'file' => 'bricolage-grotesque-700.woff2', 'weight' => '700'],
            'custom' => ['radius' => '18px', 'headingLetterSpacing' => '0em', 'headingWeight' => '700'],
        ],
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
        $accent = $this->normalize($colors->accent ?? self::BASE[$base]['accent']);

        return [
            'primary' => $this->normalize($colors->primary),
            'accent' => $accent,
            'on_accent' => $this->onAccent($accent),
            'base' => $base,
        ];
    }

    /**
     * The full theme.json style-variation array (same shape as the theme's styles/{slug}.json).
     *
     * @return array<string, mixed>
     */
    public function build(BrandColors $colors): array
    {
        $resolved = $this->resolve($colors);
        $spec = self::BASE[$resolved['base']];
        $n = $spec['neutrals'];

        $palette = [
            ['slug' => 'base', 'name' => 'Base', 'color' => $n['base']],
            ['slug' => 'surface', 'name' => 'Surface', 'color' => $n['surface']],
            ['slug' => 'contrast', 'name' => 'Contrast', 'color' => $n['contrast']],
            ['slug' => 'muted', 'name' => 'Muted', 'color' => $n['muted']],
            ['slug' => 'border', 'name' => 'Border', 'color' => $n['border']],
            ['slug' => 'primary', 'name' => 'Primary', 'color' => $resolved['primary']],
            ['slug' => 'accent', 'name' => 'Accent', 'color' => $resolved['accent']],
            ['slug' => 'on-accent', 'name' => 'On accent', 'color' => $resolved['on_accent']],
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
                        'name' => $spec['heading']['name'],
                        'fontFamily' => $spec['heading']['family'],
                        'fontFace' => [[
                            'fontFamily' => $spec['heading']['name'],
                            'fontWeight' => $spec['heading']['weight'],
                            'fontStyle' => 'normal',
                            'fontDisplay' => 'swap',
                            'src' => ['file:./assets/fonts/'.$spec['heading']['file']],
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
                'custom' => $spec['custom'],
            ],
        ];
    }

    /** Nearest curated base by the logo primary's tone: warm hue → Warm; cool → Bold (dark) / Clean. */
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
