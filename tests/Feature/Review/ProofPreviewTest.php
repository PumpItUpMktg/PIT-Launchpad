<?php

use App\ContentEngine\Review\ProofPreview;
use App\Styling\StyleVariation;
use Tests\Support\PageFixture;

it('renders the kit sections in order with real copy, brand kit, and the SEO strip', function () {
    $page = PageFixture::intakePage([
        'slot_payload' => ['hero_problem' => 'No hot water?', 'hero_solution' => 'Same-day install.'],
        'meta' => ['seo' => ['title' => 'Tankless Install', 'meta_description' => 'Endless hot water.']],
    ]);

    $preview = (new ProofPreview)->for($page->fresh());

    // brand kit is applied (colors present, with sane defaults)
    expect($preview['brand'])->toHaveKeys(['name', 'logo_url', 'primary', 'accent'])
        ->and($preview['brand']['primary'])->toBeString();

    // sections follow the kit schema order and carry the real copy
    $keys = collect($preview['sections'])->pluck('key');
    expect($keys)->toContain('hero_problem')->toContain('hero_solution');
    $hero = collect($preview['sections'])->firstWhere('key', 'hero_problem');
    expect($hero['value'])->toBe('No hot water?')
        ->and($hero['empty'])->toBeFalse()
        ->and($hero['editable'])->toBeTrue(); // generated copy is correctable in place

    // SEO "search appearance" strip + the locked permalink
    expect($preview['seo']['title'])->toBe('Tankless Install')
        ->and($preview['seo']['meta_description'])->toBe('Endless hot water.')
        ->and($preview['permalink'])->toStartWith('/');
});

it('marks entity-sourced slots as non-editable and empty slots as empty', function () {
    $page = PageFixture::intakePage(['slot_payload' => []]); // nothing filled

    $sections = collect((new ProofPreview)->for($page->fresh())['sections']);

    // every section is empty (no draft copy yet)
    expect($sections->every(fn ($s) => $s['empty'] === true))->toBeTrue();
    // entity-sourced slots (platform-filled proof/NAP) are not edited in the proof step
    expect($sections->contains(fn ($s) => $s['editable'] === false))->toBeTrue();
});

it('styles the proof in the site ACTIVE theme.json variation, not the raw Account palette', function () {
    $page = PageFixture::intakePage();
    $page->site->forceFill(['style_variation' => StyleVariation::Bold->value])->save();

    $brand = (new ProofPreview)->for($page->fresh())['brand'];

    // Bold variation — the look the page actually ships in.
    expect($brand['primary'])->toBe('#0B1F33')
        ->and($brand['accent'])->toBe('#EA580C')
        ->and($brand['heading_font'])->toBe('Archivo');
});
