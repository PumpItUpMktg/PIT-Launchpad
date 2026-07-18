<?php

namespace App\Publishing;

/**
 * Derives smaller responsive variants of a rendered raster so pages can ship a `srcset` — a phone
 * downloads a 400/800-wide image instead of the full 1200-wide hero. Pure + stateless: it takes the
 * source bytes, downscales with GD (bicubic), and re-encodes each width as WebP. It never upscales
 * (widths at or above the source are skipped) and never throws for an image it can't handle — a
 * non-decodable source or a GD build without WebP encode yields an empty map, and the caller falls
 * back to serving the single source image (no srcset). The fal render (1200×675 WebP) stays the
 * intrinsic/largest candidate; these are strictly the smaller steps below it.
 */
class ImageVariantGenerator
{
    /** Responsive widths to derive below the source. 400 ≈ phone, 800 ≈ tablet / half-column desktop. */
    public const WIDTHS = [400, 800];

    /** WebP quality for the downscaled variants — smaller images tolerate stronger compression. */
    private const QUALITY = 82;

    /**
     * Downscale $bytes to each configured width smaller than $sourceWidth, re-encoding as WebP.
     *
     * @return array<int, string> width => webp bytes, ascending; empty when nothing can be derived
     */
    public function derive(string $bytes, int $sourceWidth): array
    {
        if ($bytes === '' || ! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return [];
        }

        $widths = array_values(array_filter(self::WIDTHS, fn (int $w): bool => $w < $sourceWidth));
        if ($widths === []) {
            return [];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return [];
        }

        $out = [];
        foreach ($widths as $width) {
            $scaled = imagescale($src, $width);
            if ($scaled === false) {
                continue;
            }
            // Preserve transparency through the re-encode (fal renders are opaque, but a logo/PNG slot
            // routed through here must not get a black background).
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);

            ob_start();
            $ok = imagewebp($scaled, null, self::QUALITY);
            $data = (string) ob_get_clean();
            imagedestroy($scaled);

            if ($ok && $data !== '') {
                $out[$width] = $data;
            }
        }
        imagedestroy($src);

        return $out;
    }
}
