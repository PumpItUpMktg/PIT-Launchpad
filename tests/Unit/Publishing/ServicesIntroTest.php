<?php

use App\Publishing\Blocks\BlockBuilder;
use App\Publishing\Blocks\BlockSections;

/**
 * The "Services in {City}" intro carries drafter-woven internal service cross-links. It must render as a
 * real anchor, not as escaped, visible "<a href=…>…</a>" text (the Hoboken market-page regression).
 */
it('renders an internal link in the services intro as an anchor, not escaped text', function () {
    $sections = new BlockSections(new BlockBuilder);

    $markup = $sections->servicesGrid('What we do here', 'Services in Hoboken', [
        ['title' => 'Sump Pump Repair', 'blurb' => 'x', 'url' => 'https://x.test/sump-pump-repair'],
    ], intro: 'We also handle <a href="/water-damage-cleanup">Water Damage Cleanup</a> for flooded basements.');

    expect($markup)
        ->toContain('href="/water-damage-cleanup/"')           // a real anchor, canonical trailing slash (A7 pt2)…
        ->and($markup)->toContain('Water Damage Cleanup</a>')  // …wrapping the link text
        ->and($markup)->not->toContain('&lt;a href')           // never the escaped form
        ->and($markup)->toContain('lp-services-intro');
});

it('normalizes drafter internal prose links to the trailing slash, leaving files/anchors/tel/external alone', function () {
    $sections = new BlockSections(new BlockBuilder);

    $intro = 'See <a href="/french-drains">drains</a>, <a href="/faq/">faq</a>, '
        .'<a href="/guide.pdf">pdf</a>, <a href="tel:5551212">call</a>, and <a href="https://x.test/ext">ext</a>.';
    $markup = $sections->servicesGrid('What we do here', 'Services in Hoboken', [
        ['title' => 'Sump Pump Repair', 'blurb' => 'x', 'url' => 'https://x.test/sump-pump-repair'],
    ], intro: $intro);

    expect($markup)
        ->toContain('href="/french-drains/"')                  // slashless internal → slash added
        ->and($markup)->toContain('href="/faq/"')              // already slashed → unchanged
        ->and($markup)->not->toContain('/faq//')               // …never doubled
        ->and($markup)->toContain('href="/guide.pdf"')         // a file (dotted last segment) → untouched
        ->and($markup)->toContain('href="tel:5551212"')        // tel: → untouched
        ->and($markup)->toContain('href="https://x.test/ext"'); // external → untouched
});

it('sanitizes unsafe markup in the services intro (scripts/handlers stripped)', function () {
    $sections = new BlockSections(new BlockBuilder);

    $markup = $sections->servicesGrid('What we do here', 'Services in Hoboken', [
        ['title' => 'Sump Pump Repair', 'blurb' => 'x', 'url' => 'https://x.test/sump-pump-repair'],
    ], intro: 'Safe <strong>emphasis</strong> <script>alert(1)</script> and <a href="javascript:alert(1)">bad</a>.');

    expect($markup)
        ->toContain('<strong>emphasis</strong>')
        ->and($markup)->not->toContain('<script')
        ->and($markup)->not->toContain('javascript:');
});
