<?php

namespace App\Branding;

/**
 * Extracts the usable brand colors from an uploaded logo — the input to the "Your brand colors"
 * theme.json variation. Raster logos (PNG/JPG) are quantized with GD; SVGs are parsed for their
 * declared fill/stroke hex colors (GD can't rasterize SVG, and the source colors are authoritative).
 *
 * The QUALITY GUARD lives here: only NON-NEUTRAL, sufficiently-saturated colors count (near-white,
 * near-black and grays are dropped — they're never a brand's identity color). Result:
 *   - two distinct usable colors → primary + accent from the logo,
 *   - one usable color (monochrome) → primary only, accent borrowed downstream,
 *   - none → null, and the option doesn't appear (degrade by omission, never a broken palette).
 */
final class LogoColorExtractor
{
    /** A color counts as usable brand ink only above this saturation and within this lightness band. */
    private const MIN_SATURATION = 0.18;

    private const MIN_LIGHTNESS = 0.10;

    private const MAX_LIGHTNESS = 0.92;

    /** Accent must sit at least this far (degrees) from primary in hue to count as a second color. */
    private const MIN_HUE_SEPARATION = 25.0;

    /** Raster logos are downscaled to this longest edge before sampling (speed; detail is irrelevant). */
    private const SAMPLE_EDGE = 96;

    public function extract(string $bytes, string $extension): ?BrandColors
    {
        $ranked = strtolower(ltrim($extension, '.')) === 'svg'
            ? $this->fromSvg($bytes)
            : $this->fromRaster($bytes);

        if ($ranked === []) {
            return null;
        }

        $primary = $ranked[0];
        $accent = null;
        foreach (array_slice($ranked, 1) as $candidate) {
            if ($this->hueDistance($primary, $candidate) >= self::MIN_HUE_SEPARATION) {
                $accent = $candidate;
                break;
            }
        }

        return new BrandColors($primary, $accent);
    }

    /**
     * Usable colors from a raster logo, most-frequent first. Pixels are quantized into coarse buckets,
     * neutrals dropped, then buckets ranked by weight.
     *
     * @return list<string> hex colors
     */
    private function fromRaster(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return [];
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

        /** @var array<string, float> $weights  bucket hex => weight */
        $weights = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F; // 0 opaque … 127 transparent
                if ($alpha > 64) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if (! $this->isUsable($r, $g, $b)) {
                    continue;
                }
                // Quantize to 24 levels/channel so near-identical inks merge into one bucket.
                $hex = $this->rgbToHex($this->quantize($r), $this->quantize($g), $this->quantize($b));
                $weights[$hex] = ($weights[$hex] ?? 0) + 1;
            }
        }
        imagedestroy($image);

        return $this->rank($weights);
    }

    /**
     * Usable colors declared in an SVG's fills/strokes, most-frequent first. Handles #rrggbb, #rgb and
     * rgb()/rgba() forms wherever they appear (attributes or inline styles).
     *
     * @return list<string> hex colors
     */
    private function fromSvg(string $bytes): array
    {
        /** @var array<string, float> $weights */
        $weights = [];

        // SVG source colors are the brand's actual, exact inks — kept verbatim (never quantized).
        if (preg_match_all('/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b/', $bytes, $hexMatches)) {
            foreach ($hexMatches[1] as $token) {
                $rgb = $this->hexToRgb($this->expandHex($token));
                if ($this->isUsable(...$rgb)) {
                    $hex = $this->rgbToHex(...$rgb);
                    $weights[$hex] = ($weights[$hex] ?? 0) + 1;
                }
            }
        }

        if (preg_match_all('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $bytes, $rgbMatches, PREG_SET_ORDER)) {
            foreach ($rgbMatches as $m) {
                $rgb = [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
                if ($this->isUsable(...$rgb)) {
                    $hex = $this->rgbToHex(...$rgb);
                    $weights[$hex] = ($weights[$hex] ?? 0) + 1;
                }
            }
        }

        return $this->rank($weights);
    }

    /**
     * Rank quantized buckets by weight and return their hex, dropping near-duplicate hues so primary
     * and any accent are visibly distinct.
     *
     * @param  array<string, float>  $weights
     * @return list<string>
     */
    private function rank(array $weights): array
    {
        arsort($weights);

        $out = [];
        foreach (array_keys($weights) as $hex) {
            $dupe = false;
            foreach ($out as $kept) {
                if ($this->hueDistance($kept, $hex) < self::MIN_HUE_SEPARATION) {
                    $dupe = true;
                    break;
                }
            }
            if (! $dupe) {
                $out[] = $hex;
            }
        }

        return $out;
    }

    private function isUsable(int $r, int $g, int $b): bool
    {
        [, $s, $l] = $this->rgbToHsl($r, $g, $b);

        return $s >= self::MIN_SATURATION && $l >= self::MIN_LIGHTNESS && $l <= self::MAX_LIGHTNESS;
    }

    private function quantize(int $channel): int
    {
        $step = 256 / 24;

        return (int) min(255, round(round($channel / $step) * $step));
    }

    private function hueDistance(string $a, string $b): float
    {
        $ha = $this->rgbToHsl(...$this->hexToRgb($a))[0];
        $hb = $this->rgbToHsl(...$this->hexToRgb($b))[0];
        $d = abs($ha - $hb);

        return min($d, 360 - $d);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private function expandHex(string $token): string
    {
        if (strlen($token) === 3) {
            return $token[0].$token[0].$token[1].$token[1].$token[2].$token[2];
        }

        return $token;
    }

    private function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue 0–360, saturation 0–1, lightness 0–1
     */
    private function rgbToHsl(int $r, int $g, int $b): array
    {
        $rf = $r / 255;
        $gf = $g / 255;
        $bf = $b / 255;
        $max = max($rf, $gf, $bf);
        $min = min($rf, $gf, $bf);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $rf => (($gf - $bf) / $d) + ($gf < $bf ? 6 : 0),
            $gf => (($bf - $rf) / $d) + 2,
            default => (($rf - $gf) / $d) + 4,
        };

        return [$h * 60, $s, $l];
    }
}
