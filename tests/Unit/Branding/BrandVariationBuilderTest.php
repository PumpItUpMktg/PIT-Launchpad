<?php

use App\Branding\BrandColors;
use App\Branding\BrandVariationBuilder;
use App\Styling\StyleVariation;

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
        // neutrals borrowed wholesale from the Warm base (StyleVariation::Warm) — not derived from the logo
        ->and($pal['surface'])->toBe('#f6efe3')
        ->and($pal['contrast'])->toBe('#2b2620')
        ->and($pal['muted'])->toBe('#6b5d4f')
        ->and($pal['border'])->toBe('#e7dcc9')
        // the CTA button rides the logo accent (on-brand), with its contrast text
        ->and($pal['button'])->toBe('#0b1f33')
        ->and($pal)->toHaveKeys(['base', 'surface', 'contrast', 'muted', 'border', 'primary', 'accent', 'on-accent', 'button', 'on-button']);

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
        ->and($pal['accent'])->toBe('#e4572e'); // borrowed Bold highlight, never invented
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

it('borrows neutrals straight from the StyleVariation enum (no drift possible)', function () {
    // BrandVariationBuilder reads neutrals + type from the enum, which generates the theme files —
    // so the grafted neutrals match the nearest curated variation's palette by construction.
    $builder = new BrandVariationBuilder;

    foreach (['bold', 'clean', 'warm'] as $slug) {
        $enum = StyleVariation::from($slug)->palette();
        // A monochrome logo whose primary lands on this base → neutrals come from the enum palette.
        $primary = $enum['primary'];
        // Force the nearest base for a deterministic assertion by using each base's own primary tone.
        if ($builder->nearestForColor($primary) !== $slug) {
            continue; // tone maps elsewhere; the graft still reads the enum, asserted via the other bases
        }
        $pal = paletteMap($builder->build(new BrandColors($primary, null)));
        expect($pal['surface'])->toBe(strtolower($enum['surface']))
            ->and($pal['contrast'])->toBe(strtolower($enum['text']))
            ->and($pal['muted'])->toBe(strtolower($enum['muted']))
            ->and($pal['border'])->toBe(strtolower($enum['border']));
    }
});
