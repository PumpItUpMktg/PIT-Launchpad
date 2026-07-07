<?php

use App\Branding\BrandColors;
use App\Branding\BrandVariationBuilder;

function paletteMap(array $variation): array
{
    $out = [];
    foreach ($variation['settings']['color']['palette'] as $c) {
        $out[$c['slug']] = strtolower($c['color']);
    }

    return $out;
}

it('grafts the logo primary + accent onto the nearest curated base — a complete, coherent palette', function () {
    // A warm orange primary → nearest base is Warm; neutrals borrowed from Warm.
    $variation = (new BrandVariationBuilder)->build(new BrandColors('#EA580C', '#0B1F33'));

    $pal = paletteMap($variation);
    expect($pal['primary'])->toBe('#ea580c')     // from the logo
        ->and($pal['accent'])->toBe('#0b1f33')   // from the logo
        // neutrals borrowed wholesale from the Warm base — not derived from the two logo colors
        ->and($pal['surface'])->toBe('#f4f1ea')
        ->and($pal['contrast'])->toBe('#14261e')
        ->and($pal['muted'])->toBe('#4b5a52')
        ->and($pal['border'])->toBe('#e4ded1')
        ->and($pal)->toHaveKeys(['base', 'surface', 'contrast', 'muted', 'border', 'primary', 'accent', 'on-accent']);

    // Complete variation shape: heading + body font families present, custom tokens present.
    expect($variation['settings']['typography']['fontFamilies'])->toHaveCount(2)
        ->and($variation['settings']['custom'])->toHaveKeys(['radius', 'headingLetterSpacing', 'headingWeight'])
        ->and($variation['title'])->toBe('Your brand colors');
});

it('borrows the accent from the nearest base for a monochrome logo', function () {
    // Cool dark blue, no accent → nearest base Bold → accent borrowed = Bold accent.
    $variation = (new BrandVariationBuilder)->build(new BrandColors('#0B1F33', null));

    $pal = paletteMap($variation);
    expect($pal['primary'])->toBe('#0b1f33')
        ->and($pal['accent'])->toBe('#ea580c'); // borrowed Bold accent, never invented
});

it('picks the nearest base by tone', function () {
    $b = new BrandVariationBuilder;
    expect($b->nearestForColor('#DD8A2B'))->toBe('warm')  // warm amber
        ->and($b->nearestForColor('#0B1F33'))->toBe('bold')  // cool + dark
        ->and($b->nearestForColor('#1D6FD6'))->toBe('clean'); // cool + light
});

it('chooses on-accent for contrast (white on dark, dark on light)', function () {
    $b = new BrandVariationBuilder;
    expect($b->resolve(new BrandColors('#0B1F33', '#0B1F33'))['on_accent'])->toBe('#ffffff') // dark accent
        ->and($b->resolve(new BrandColors('#0B1F33', '#f5c518'))['on_accent'])->toBe('#1f2937'); // light accent
});

it('base neutrals stay in lockstep with the theme styles files (no drift)', function () {
    $ref = new ReflectionClass(BrandVariationBuilder::class);
    $bases = $ref->getConstant('BASE');

    $root = dirname(__DIR__, 3); // project root from tests/Unit/Branding
    foreach (['bold', 'clean', 'warm'] as $slug) {
        $theme = json_decode((string) file_get_contents("{$root}/wordpress-theme/launchpad-blocks/styles/{$slug}.json"), true);
        $themePalette = [];
        foreach ($theme['settings']['color']['palette'] as $c) {
            $themePalette[$c['slug']] = $c['color'];
        }

        foreach (['base', 'surface', 'contrast', 'muted', 'border'] as $slot) {
            expect($bases[$slug]['neutrals'][$slot])->toBe($themePalette[$slot], "{$slug}.{$slot} drifted from the theme");
        }
        expect($bases[$slug]['accent'])->toBe($themePalette['accent'], "{$slug} accent drifted");
    }
});
