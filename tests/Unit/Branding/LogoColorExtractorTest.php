<?php

use App\Branding\LogoColorExtractor;

/** A PNG (base64→bytes) painted with the given solid rectangles on a transparent canvas. */
function pngWith(array $rects, int $w = 64, int $h = 64): string
{
    $img = imagecreatetruecolor($w, $h);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127)); // transparent
    foreach ($rects as [$hex, $x0, $y0, $x1, $y1]) {
        [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        imagefilledrectangle($img, $x0, $y0, $x1, $y1, imagecolorallocate($img, $r, $g, $b));
    }
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('pulls a primary + accent from a two-color raster logo', function () {
    // 60% orange, 40% navy.
    $png = pngWith([['#ea580c', 0, 0, 63, 37], ['#0b1f33', 0, 38, 63, 63]]);

    $colors = app(LogoColorExtractor::class)->extract($png, 'png');

    expect($colors)->not->toBeNull()
        ->and($colors->primary)->toBeString()
        ->and($colors->accent)->not->toBeNull()
        ->and($colors->isMonochrome())->toBeFalse();

    // The two extracted hues should be far apart (orange vs navy), not two shades of one color.
    $hue = fn (string $hex) => (function () use ($hex) {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min ?: 1;
        $h = $max === $r ? (($g - $b) / $d) : ($max === $g ? (($b - $r) / $d) + 2 : (($r - $g) / $d) + 4);

        return fmod(($h * 60) + 360, 360);
    })();
    $sep = abs($hue($colors->primary) - $hue($colors->accent));
    expect(min($sep, 360 - $sep))->toBeGreaterThan(25);
});

it('returns a monochrome result (primary only, accent borrowed downstream) for a one-color logo', function () {
    $png = pngWith([['#14513f', 8, 8, 55, 55]]); // single pine block

    $colors = app(LogoColorExtractor::class)->extract($png, 'png');

    expect($colors)->not->toBeNull()
        ->and($colors->isMonochrome())->toBeTrue()
        ->and($colors->accent)->toBeNull();
});

it('returns null for a logo with no usable (non-neutral) color — the option must not appear', function () {
    $png = pngWith([['#ffffff', 0, 0, 31, 63], ['#111111', 32, 0, 63, 63]]); // white + near-black only

    expect(app(LogoColorExtractor::class)->extract($png, 'png'))->toBeNull();
});

it('parses fill/stroke hex colors from an SVG', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="#1d6fd6"/><rect fill="#1d6fd6"/><path stroke="#dd8a2b" style="fill:#ffffff"/></svg>';

    $colors = app(LogoColorExtractor::class)->extract($svg, 'svg');

    expect($colors)->not->toBeNull()
        ->and($colors->primary)->toBe('#1d6fd6')   // most frequent usable
        ->and($colors->accent)->toBe('#dd8a2b');   // second distinct hue (white dropped as neutral)
});
