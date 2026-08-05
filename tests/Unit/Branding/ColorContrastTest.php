<?php

use App\Branding\ColorContrast;

it('picks the higher-contrast text color for a background (onColor)', function () {
    // A mid-tone orange (SPG's accent): white is ~3.5:1 (fails), near-black is ~5:1 — near-black wins.
    expect(ColorContrast::onColor('#ea580c'))->toBe('#111827')
        // A dark navy: white wins.
        ->and(ColorContrast::onColor('#0b1f33'))->toBe('#ffffff')
        // A bright yellow: near-black wins.
        ->and(ColorContrast::onColor('#f5c518'))->toBe('#111827');
});

it('always returns a color that clears 4.5:1 against the background (onColor)', function (string $bg) {
    $on = ColorContrast::onColor($bg);

    expect(ColorContrast::ratio($on, $bg))->toBeGreaterThanOrEqual(4.5);
})->with(['#ea580c', '#e08d3c', '#14b8a6', '#4c9a2a', '#f97316', '#c8102e', '#38bdf8', '#1d6fd6', '#ffffff', '#000000']);

it('darkens a mid-tone accent until it reads as text on a light surface (ink)', function () {
    $ink = ColorContrast::ink('#ea580c', '#ffffff');

    // The raw orange fails on white; the derived ink must clear 4.5:1.
    expect(ColorContrast::ratio('#ea580c', '#ffffff'))->toBeLessThan(4.5)
        ->and(ColorContrast::ratio($ink, '#ffffff'))->toBeGreaterThanOrEqual(4.5);
});

it('leaves an already-legible accent unchanged (ink)', function () {
    // A deep blue already clears 4.5:1 on white — ink returns it as-is (hue preserved).
    expect(ColorContrast::ink('#123b6b', '#ffffff'))->toBe('#123b6b');
});

it('lifts the accent toward white when the surface is dark (ink)', function () {
    // On a dark base a too-dark accent is lightened, not darkened.
    $ink = ColorContrast::ink('#1e5233', '#0b1220');

    expect(ColorContrast::ratio($ink, '#0b1220'))->toBeGreaterThanOrEqual(4.5);
});
