<?php

use App\Branding\LogoHeaderTone;

/** Build a small PNG: $alpha=true starts fully transparent; $draw paints the "ink". */
function tonePng(callable $draw, bool $alpha): string
{
    $img = imagecreatetruecolor(40, 20);
    if ($alpha) {
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    }
    $draw($img);
    ob_start();
    imagepng($img);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

it('picks a DARK header for a light logo on transparency (contrast the ink)', function () {
    $bytes = tonePng(function ($img): void {
        // ~30% of the width painted white; the rest stays transparent.
        imagefilledrectangle($img, 0, 0, 11, 19, imagecolorallocate($img, 255, 255, 255));
    }, alpha: true);

    expect(new LogoHeaderTone()->forLogo($bytes, 'png'))->toBe(LogoHeaderTone::DARK);
});

it('picks a LIGHT header for a dark logo on transparency', function () {
    $bytes = tonePng(function ($img): void {
        imagefilledrectangle($img, 0, 0, 11, 19, imagecolorallocate($img, 20, 22, 26));
    }, alpha: true);

    expect(new LogoHeaderTone()->forLogo($bytes, 'png'))->toBe(LogoHeaderTone::LIGHT);
});

it('MATCHES a baked-in background: white card → light, dark card → dark', function () {
    $whiteCard = tonePng(fn ($img) => imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255)), alpha: false);
    $darkCard = tonePng(fn ($img) => imagefill($img, 0, 0, imagecolorallocate($img, 15, 20, 40)), alpha: false);

    expect(new LogoHeaderTone()->forLogo($whiteCard, 'png'))->toBe(LogoHeaderTone::LIGHT)
        ->and(new LogoHeaderTone()->forLogo($darkCard, 'png'))->toBe(LogoHeaderTone::DARK);
});

it('reads an SVG by its declared ink, defaulting to DARK (the status-quo header) when undecidable', function () {
    $tone = new LogoHeaderTone;
    expect($tone->forLogo('<svg><path fill="#ffffff"/><path fill="#f4f4f4"/></svg>', 'svg'))->toBe(LogoHeaderTone::DARK)
        ->and($tone->forLogo('<svg><path fill="#111827"/></svg>', 'svg'))->toBe(LogoHeaderTone::LIGHT)
        // no signal → keep the platform's standard dark header, don't flip the whole bar
        ->and($tone->forLogo('<svg></svg>', 'svg'))->toBe(LogoHeaderTone::DARK)
        ->and($tone->forLogo('not an image', 'png'))->toBe(LogoHeaderTone::DARK);
});
