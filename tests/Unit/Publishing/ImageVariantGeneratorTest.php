<?php

use App\Publishing\ImageVariantGenerator;

/** A real WebP raster at the given size (GD is compiled with WebP in this environment). */
function webpBytes(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 12, 120, 200));
    ob_start();
    imagewebp($im, null, 82);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

it('derives the configured widths below the source, ascending, as decodable WebP', function () {
    $variants = (new ImageVariantGenerator)->derive(webpBytes(1200, 675), 1200);

    expect(array_keys($variants))->toBe(ImageVariantGenerator::WIDTHS); // [400, 800], in order

    foreach ($variants as $width => $bytes) {
        $img = imagecreatefromstring($bytes);
        expect($img)->not->toBeFalse()
            ->and(imagesx($img))->toBe($width);
        // Aspect ratio preserved (675/1200 = 0.5625).
        expect(imagesy($img))->toBe((int) round($width * 675 / 1200));
        imagedestroy($img);
    }
});

it('never upscales — widths at or above the source are skipped', function () {
    // Source is 500 wide: only the 400 variant is smaller; 800 would upscale and is dropped.
    $variants = (new ImageVariantGenerator)->derive(webpBytes(500, 281), 500);

    expect(array_keys($variants))->toBe([400]);
});

it('returns nothing for a source no larger than the smallest width', function () {
    expect((new ImageVariantGenerator)->derive(webpBytes(400, 225), 400))->toBe([]);
});

it('returns nothing (no throw) for undecodable bytes', function () {
    expect((new ImageVariantGenerator)->derive('not an image', 1200))->toBe([])
        ->and((new ImageVariantGenerator)->derive('', 1200))->toBe([]);
});

it('produces materially smaller variants than the source', function () {
    $source = webpBytes(1200, 675);
    $variants = (new ImageVariantGenerator)->derive($source, 1200);

    // The 400w variant is a fraction of the source weight — the whole point of the srcset.
    expect(strlen($variants[400]))->toBeLessThan(strlen($source));
});
