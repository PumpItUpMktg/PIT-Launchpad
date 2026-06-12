<?php

use App\Support\SeoTitle;

it('strips a pipe-suffixed brand from the title', function () {
    expect(SeoTitle::normalize('Same-Day Water Heater Repair | Apex Plumbing'))
        ->toBe('Same-Day Water Heater Repair');
});

it('removes the source/publication name in attribution positions only', function () {
    expect(SeoTitle::normalize('Tankless Rebates Explained - Local Tribune', 'Local Tribune'))
        ->toBe('Tankless Rebates Explained')
        ->and(SeoTitle::normalize('New Rebate Details (Entrepreneur)', 'Entrepreneur'))
        ->toBe('New Rebate Details')
        ->and(SeoTitle::normalize('What the rebate means, according to AOL', 'AOL'))
        ->toBe('What the rebate means,'); // attribution phrase removed
});

it('does not strip a source name used as a legitimate topic word', function () {
    // "Entrepreneur" mid-title is a topic word, not attribution — keep it.
    expect(SeoTitle::normalize('Tax Tips Every Entrepreneur Should Know', 'Entrepreneur'))
        ->toBe('Tax Tips Every Entrepreneur Should Know');
});

it('caps the title at ~60 characters on a word boundary', function () {
    $long = 'Everything Homeowners Need To Know About Tankless Water Heater Rebates This Year';
    $result = SeoTitle::normalize($long);

    expect(mb_strlen($result))->toBeLessThanOrEqual(60)
        ->and($result)->toBe('Everything Homeowners Need To Know About Tankless Water')
        ->and($long)->toStartWith($result); // a clean prefix, no mid-word cut
});

it('is idempotent on an already-clean title', function () {
    $clean = 'Same-Day Tankless Water Heater Installation';
    expect(SeoTitle::normalize($clean, 'Local Tribune'))->toBe($clean);
});
