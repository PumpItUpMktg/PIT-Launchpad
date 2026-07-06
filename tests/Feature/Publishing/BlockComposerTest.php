<?php

use App\Publishing\Blocks\BlockBuilder;
use App\Publishing\Blocks\BlockPageComposer;
use App\Publishing\Blocks\BlockSections;
use App\Publishing\Blocks\PageContext;

function composer(): BlockPageComposer
{
    return new BlockPageComposer(new BlockSections(new BlockBuilder));
}

/** Every wp: block used, deduped. */
function blockTypes(string $markup): array
{
    preg_match_all('/<!-- wp:([a-z0-9-]+)/', $markup, $m);

    return array_values(array_unique($m[1]));
}

const CORE_BLOCKS = ['group', 'columns', 'column', 'heading', 'paragraph', 'buttons', 'button', 'image', 'list', 'list-item', 'spacer', 'html'];

$slots = [
    'hero_headline' => 'Stop sewer problems before they shut you down.',
    'hero_subhead' => 'Preventive drain, sewer, and pump maintenance for commercial buildings across Northern NJ.',
    'service_area' => 'Commercial Plumbing · Northern New Jersey',
];
$images = ['hero_image' => ['url' => 'https://cdn.example/hero.webp', 'alt' => 'A technician on site']];
$cards = [
    ['title' => 'Drain Cleaning', 'blurb' => 'Snaking and hydro-jetting.', 'url' => 'https://sewergurus.com/drain-cleaning'],
    ['title' => 'Sewer Line Services', 'blurb' => 'Repair and replacement.', 'url' => 'https://sewergurus.com/sewer-line-services'],
];

it('emits ONLY core blocks and balanced block comments', function () use ($slots, $images, $cards) {
    $ctx = new PageContext('(973) 555-0100', 'tel:+19735550100', emergency: true);
    $markup = composer()->composeHome($slots, $images, $cards, $ctx);

    // No custom blocks — portability is the whole point of the pivot.
    expect(array_diff(blockTypes($markup), CORE_BLOCKS))->toBe([]);

    // Every opening block comment has a matching close.
    $opens = preg_match_all('/<!-- wp:[a-z0-9-]+/', $markup);
    $closes = preg_match_all('/<!-- \/wp:[a-z0-9-]+/', $markup);
    expect($opens)->toBe($closes);

    // The split hero: a primary-background group with two columns and the AI image.
    expect($markup)->toContain('<!-- wp:group {"backgroundColor":"primary"')
        ->toContain('wp:columns')
        ->toContain('<img src="https://cdn.example/hero.webp"')
        ->toContain('Stop sewer problems before they shut you down.');
});

it('emergency ON makes the phone the primary CTA with 24/7 framing', function () use ($slots, $images, $cards) {
    $ctx = new PageContext('(973) 555-0100', 'tel:+19735550100', emergency: true);
    $markup = composer()->composeHome($slots, $images, $cards, $ctx);

    // The call button leads and is the ACCENT (primary) button.
    expect($markup)->toContain('href="tel:+19735550100"')
        ->toContain('Call (973) 555-0100')
        // 24/7 trust stat (honest — the business opted into emergency).
        ->toContain('24/7')
        ->toContain('Emergency response')
        // the CTA carries a "call now" line
        ->toContain('Or call now:');

    // The call button appears before the assessment button (primary leads).
    expect(mb_strpos($markup, 'Call (973) 555-0100'))->toBeLessThan(mb_strpos($markup, 'Get a free assessment'));
});

it('emergency OFF keeps the number calm — assessment leads, no 24/7 claim', function () use ($slots, $images, $cards) {
    $ctx = new PageContext('(973) 555-0100', 'tel:+19735550100', emergency: false);
    $markup = composer()->composeHome($slots, $images, $cards, $ctx);

    // No false 24/7 claim; no "call now" urgency line.
    expect($markup)->not->toContain('24/7')
        ->not->toContain('Or call now:')
        // the phone still renders (click-to-call), just secondary
        ->toContain('href="tel:+19735550100"');

    // Assessment leads now (appears before the call button).
    expect(mb_strpos($markup, 'Get a free assessment'))->toBeLessThan(mb_strpos($markup, 'Call (973) 555-0100'));
});

it('omits the call entirely when there is no phone', function () use ($slots, $images, $cards) {
    $ctx = new PageContext(null, null, emergency: true);
    $markup = composer()->composeHome($slots, $images, $cards, $ctx);

    expect($markup)->not->toContain('tel:')
        ->not->toContain('Or call now:')
        ->not->toContain('24/7')                 // 24/7 needs a phone to call
        ->toContain('Get a free assessment');    // assessment still present
});

it('renders the services grid from real child pages only (internal-link safe)', function () use ($slots, $images, $cards) {
    $ctx = new PageContext('(973) 555-0100', 'tel:+19735550100');
    $markup = composer()->composeHome($slots, $images, $cards, $ctx);

    expect($markup)->toContain('Drain Cleaning')
        ->toContain('href="https://sewergurus.com/drain-cleaning"')
        ->toContain('Sewer Line Services')
        ->toContain('Learn more →')
        // no invented URLs — only the two the caller resolved
        ->and(substr_count($markup, 'sewergurus.com/'))->toBe(2);
});

it('renders proof as honest add-your-own-photo placeholders, never a fake image', function () use ($slots, $cards) {
    $ctx = new PageContext('(973) 555-0100', 'tel:+19735550100');
    // No proof/gallery images provided.
    $markup = composer()->composeHome($slots, ['hero_image' => ['url' => 'https://cdn.example/hero.webp']], $cards, $ctx);

    expect($markup)->toContain('Add your own photo');
    // The only <img> is the hero — proof slots are placeholders, not fabricated photos.
    expect(substr_count($markup, '<img '))->toBe(1);
});

it('includes substantiated trust stats but never fabricates them', function () use ($slots, $images, $cards) {
    $ctx = new PageContext('(973) 555-0100', 'tel:+19735550100', emergency: false);

    // With no proof stats passed and emergency off, there is no trust row content beyond what's given.
    $bare = composer()->composeHome($slots, $images, $cards, $ctx);
    expect($bare)->not->toContain('Licensed');

    // Substantiated stats passed in DO render.
    $withProof = composer()->composeHome($slots, $images, $cards, $ctx, [['value' => 'Licensed', 'label' => '& insured']]);
    expect($withProof)->toContain('Licensed')->toContain('&amp; insured');
});
