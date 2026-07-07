<?php

use App\Publishing\Blocks\BlockBuilder;
use App\Publishing\Blocks\BlockSections;
use App\Publishing\Blocks\ServiceIcon;

it('maps service names to curated icon slugs, with a fallback', function () {
    $icons = new ServiceIcon;

    expect($icons->slugFor('Drain Cleaning'))->toBe('drain')
        ->and($icons->slugFor('Sewer Line Services'))->toBe('pipe')
        ->and($icons->slugFor('Camera Inspection'))->toBe('camera')
        ->and($icons->slugFor('Leak Detection'))->toBe('droplet')
        ->and($icons->slugFor('Sump Pump Services'))->toBe('pump')
        ->and($icons->slugFor('Hydro Jetting'))->toBe('jet')
        ->and($icons->slugFor('24/7 Emergency Service'))->toBe('bolt')
        ->and($icons->slugFor('Fixture Replacement'))->toBe('wrench')
        // unmatched → the fallback, never empty
        ->and($icons->slugFor('Something Bespoke'))->toBe(ServiceIcon::FALLBACK)
        ->and($icons->slugFor(''))->toBe(ServiceIcon::FALLBACK);
});

it('emits the icon as a kses-safe class, never inline SVG that WP would strip', function () {
    $sections = new BlockSections(new BlockBuilder);
    $markup = $sections->servicesGrid('What we do', 'Our services', [
        ['title' => 'Drain Cleaning', 'blurb' => 'x', 'url' => 'https://x.test/drain'],
    ]);

    expect($markup)->toContain('lp-icon lp-icon--drain')
        ->and($markup)->not->toContain('<svg'); // no inline SVG in post_content
});
