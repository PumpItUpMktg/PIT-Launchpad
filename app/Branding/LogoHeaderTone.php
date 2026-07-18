<?php

namespace App\Branding;

/**
 * Decides whether an uploaded logo is better shown on a DARK or LIGHT header background — so the header
 * is chosen to make the logo legible instead of guessing. Distinct from {@see LogoColorExtractor} (which
 * pulls brand INK for the palette): here neutrals matter, because a white wordmark and a black wordmark
 * want opposite headers.
 *
 * Heuristic, by how the logo was drawn:
 *  - a transparent logo (ink floating on nothing) → CONTRAST the ink: light ink → dark header, dark ink
 *    → light header, so the mark stands off the bar;
 *  - a logo with a baked-in solid background → MATCH that background: a white-card logo wants a light
 *    header, a dark-card logo a dark one, so the logo's own panel blends into the bar.
 *
 * Defaults to 'light' whenever the tone can't be read (undecodable bytes, an all-transparent image, or an
 * SVG with no declared colors) — 'light' is the clean default bar (and the plugin's own render fallback),
 * so an unreadable logo simply lands on the neutral white header rather than forcing the branded bar. A
 * logo only flips to 'dark' when its ink genuinely reads better there. The operator can override downstream.
 */
final class LogoHeaderTone
{
    public const DARK = 'dark';

    public const LIGHT = 'light';

    private const SAMPLE_EDGE = 96;

    /** @return self::DARK|self::LIGHT */
    public function forLogo(string $bytes, string $extension): string
    {
        return strtolower(ltrim($extension, '.')) === 'svg'
            ? $this->fromSvg($bytes)
            : $this->fromRaster($bytes);
    }

    private function fromRaster(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return self::LIGHT;
        }

        [$w, $h] = [imagesx($image), imagesy($image)];
        $scale = max($w, $h) > self::SAMPLE_EDGE ? self::SAMPLE_EDGE / max($w, $h) : 1.0;
        if ($scale < 1.0) {
            $scaled = imagescale($image, (int) round($w * $scale), (int) round($h * $scale));
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
                [$w, $h] = [imagesx($image), imagesy($image)];
            }
        }

        $total = 0;
        $transparent = 0;
        $opaque = 0;
        $lumSum = 0.0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $total++;
                if ((($rgba >> 24) & 0x7F) > 96) { // near-transparent → not part of the mark
                    $transparent++;

                    continue;
                }
                $opaque++;
                $lumSum += $this->relativeLuminance(($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF);
            }
        }
        imagedestroy($image);

        if ($opaque === 0) {
            return self::LIGHT;
        }

        $avg = $lumSum / $opaque;
        $transparentRatio = $transparent / $total; // $total >= $opaque >= 1 here

        // Transparent logo → contrast the ink; baked-background logo → match the background.
        return $transparentRatio >= 0.5
            ? ($avg >= 0.6 ? self::DARK : self::LIGHT)
            : ($avg >= 0.5 ? self::LIGHT : self::DARK);
    }

    /**
     * SVG logos are transparent-background by convention, so contrast the declared ink: a predominantly
     * light wordmark wants a dark header. Averages every declared color's luminance; no colors → light.
     */
    private function fromSvg(string $bytes): string
    {
        $lums = [];

        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $bytes, $hex)) {
            foreach ($hex[1] as $token) {
                [$r, $g, $b] = $this->hexToRgb($this->expandHex($token));
                $lums[] = $this->relativeLuminance($r, $g, $b);
            }
        }

        if (preg_match_all('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $bytes, $rgb, PREG_SET_ORDER)) {
            foreach ($rgb as $m) {
                $lums[] = $this->relativeLuminance(min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
            }
        }

        if ($lums === []) {
            return self::LIGHT;
        }

        return array_sum($lums) / count($lums) >= 0.6 ? self::DARK : self::LIGHT;
    }

    /** WCAG relative luminance (0 black … 1 white), sRGB-linearized so the split matches perception. */
    private function relativeLuminance(int $r, int $g, int $b): float
    {
        $lin = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private function expandHex(string $token): string
    {
        return strlen($token) === 3
            ? $token[0].$token[0].$token[1].$token[1].$token[2].$token[2]
            : $token;
    }
}
