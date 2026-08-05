<?php

namespace App\Branding;

/**
 * WCAG color math — luminance + contrast ratio between two #RRGGBB colors, and hex
 * normalization. Shared by the single-brand generator and the multi-candidate
 * contrast matrix so the accessibility floor is computed one way.
 */
final class ColorContrast
{
    /** The WCAG contrast ratio (1–21) between two colors. Invalid hex → 1.0 (fails). */
    public static function ratio(string $a, string $b): float
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        if ($na === null || $nb === null) {
            return 1.0;
        }

        $la = self::luminance($na);
        $lb = self::luminance($nb);
        [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /**
     * The accessible TEXT color for a background: whichever of white / near-black has the higher WCAG
     * contrast. This replaces a naive light/dark threshold — for a mid-tone accent (e.g. orange #ea580c)
     * the threshold picks white (~3.5:1, fails), but near-black passes (~5.5:1). So the CTA button, band,
     * and on-* pairs get the legible color, brand accent unchanged.
     */
    public static function onColor(string $background): string
    {
        $white = '#ffffff';
        $ink = '#111827'; // near-black (matches the theme's contrast neutral)

        return self::ratio($white, $background) >= self::ratio($ink, $background) ? $white : $ink;
    }

    /**
     * Derive an accessible "ink" from a brand color for TEXT ON a background — darken (on a light bg) or
     * lighten (on a dark bg) the color just until it clears $min, preserving the accent's hue. Used for
     * accent-colored small text (eyebrows, meta) that would otherwise fail on the light page surface.
     */
    public static function ink(string $color, string $against = '#ffffff', float $min = 4.5): string
    {
        $norm = self::normalize($color);
        $bg = self::normalize($against);
        if ($norm === null || $bg === null) {
            return $color;
        }
        if (self::ratio($norm, $bg) >= $min) {
            return $norm; // the accent already reads fine on this background
        }

        $target = self::isLight($bg) ? '#000000' : '#ffffff';
        for ($t = 0.1; $t <= 0.95; $t += 0.1) {
            $candidate = self::mix($norm, $target, $t);
            if (self::ratio($candidate, $bg) >= $min) {
                return $candidate;
            }
        }

        return self::isLight($bg) ? '#111827' : '#ffffff';
    }

    /** Linear blend of two hex colors, $t of the way from $a to $b (0..1). */
    private static function mix(string $a, string $b, float $t): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($ar + ($br - $ar) * $t),
            (int) round($ag + ($bg - $ag) * $t),
            (int) round($ab + ($bb - $ab) * $t),
        );
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalize($hex) ?? '#000000', '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** Normalize a hex string to #rrggbb (lowercased), or null when not valid hex. */
    public static function normalize(string $hex): ?string
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

    /**
     * Is this a LIGHT color (relative luminance ≥ 0.5)? Backgrounds must be light and
     * text dark — the brand never ships an inverted/dark theme (Bold's drama comes
     * from the accent + structure tokens, not a dark surface). Invalid hex → false.
     */
    public static function isLight(string $hex): bool
    {
        $norm = self::normalize($hex);

        return $norm !== null && self::luminance($norm) >= 0.5;
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
