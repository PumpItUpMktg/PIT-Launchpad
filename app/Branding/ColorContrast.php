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
