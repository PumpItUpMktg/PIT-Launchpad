<?php

use App\Publishing\Blocks\BlockBuilder;

it('lazy-loads content images by default with async decoding', function () {
    $html = (new BlockBuilder)->image('https://cdn.example/proof.webp', 'Our work', ['className' => 'lp-proof']);

    expect($html)->toContain('loading="lazy"')
        ->toContain('decoding="async"')
        ->not->toContain('fetchpriority')
        ->not->toContain('loading="eager"');
});

it('loads an eager image with high fetch priority and never lazy (the LCP hero)', function () {
    $html = (new BlockBuilder)->image('https://cdn.example/hero.webp', 'Hero', ['loading' => 'eager']);

    expect($html)->toContain('loading="eager"')
        ->toContain('fetchpriority="high"')
        ->toContain('decoding="async"')
        ->not->toContain('loading="lazy"');
});

it('emits width/height when known but keeps them out of the block-comment JSON', function () {
    $html = (new BlockBuilder)->image('https://cdn.example/hero.webp', 'Hero', ['width' => 1200, 'height' => 675]);

    expect($html)->toContain('width="1200"')
        ->toContain('height="675"')
        // the wp:image block comment carries only WP block props, not the perf attrs
        ->not->toContain('"width":1200')
        ->not->toContain('"loading"');
});
