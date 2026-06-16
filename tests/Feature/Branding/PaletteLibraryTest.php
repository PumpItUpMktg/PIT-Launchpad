<?php

use App\Branding\BrandCandidate;
use App\Branding\PaletteLibrary;
use App\Branding\Scheme;

it('loads the curated library and filters by scheme', function () {
    $library = new PaletteLibrary;

    expect($library->all())->not->toBeEmpty()
        ->and(collect($library->forScheme(Scheme::Light))->every(fn ($p) => $p->scheme === Scheme::Light))->toBeTrue()
        ->and(collect($library->forScheme(Scheme::Dark))->every(fn ($p) => $p->scheme === Scheme::Dark))->toBeTrue();
});

it('finds a palette by id and resolves the per-scheme default', function () {
    $library = new PaletteLibrary;

    expect($library->find('midnight-current')?->name)->toBe('Midnight Current')
        ->and($library->find('nope'))->toBeNull()
        ->and($library->default(Scheme::Dark)?->id)->toBe('midnight-current')
        ->and($library->default(Scheme::Light)?->id)->toBe('slate-professional');
});

it('CERTIFIES every library palette against the full contrast surface model', function () {
    // The library IS the enforcement: each curated set must pass every rendered
    // pairing (text/muted on bg + bg_alt, on_accent on accent). CI fails if a seed
    // regresses — no un-vetted palette can ship.
    foreach ((new PaletteLibrary)->all() as $palette) {
        expect($palette->contrastFailures())->toBe([], "Palette {$palette->id} must be contrast-clean");
    }
});

it('every palette carries the full nine-token set + a font pairing', function () {
    foreach ((new PaletteLibrary)->all() as $palette) {
        expect($palette->tokens)->toHaveKeys([
            'primary', 'secondary', 'accent', 'text', 'text_muted', 'bg', 'bg_alt', 'border', 'on_accent',
        ])
            ->and($palette->fontHeading)->not->toBe('')
            ->and($palette->fontBody)->not->toBe('');
    }
});

it('round-trips a palette to the BrandCandidate the picker consumes', function () {
    $candidate = (new PaletteLibrary)->find('deep-current')?->toCandidate(recommended: true, rationale: 'Navy reads controlled.');

    expect($candidate)->toBeInstanceOf(BrandCandidate::class)
        ->and($candidate->recommended)->toBeTrue()
        ->and($candidate->rationale)->toBe('Navy reads controlled.')
        ->and($candidate->palette['bg'])->toBe('#ffffff')
        ->and($candidate->typography)->toBe(['heading' => 'Archivo', 'body' => 'Inter'])
        ->and($candidate->adjustments)->toBe([]); // curated = never nudged
});
