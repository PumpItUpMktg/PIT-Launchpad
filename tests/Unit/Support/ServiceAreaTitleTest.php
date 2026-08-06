<?php

use App\Support\ServiceAreaTitle;

it('appends the full region when it fits under the cap', function () {
    expect(ServiceAreaTitle::qualify('Basement Waterproofing', 'New Jersey & Eastern Pennsylvania', ['NJ', 'PA']))
        ->toBe('Basement Waterproofing in New Jersey & Eastern Pennsylvania'); // 59 chars
});

it('degrades to the short abbreviation form when the full region would overflow', function () {
    // "Crawl Space Encapsulation" (25) + " in New Jersey & Eastern Pennsylvania" would be 62 (> 60).
    expect(ServiceAreaTitle::qualify('Crawl Space Encapsulation', 'New Jersey & Eastern Pennsylvania', ['NJ', 'PA']))
        ->toBe('Crawl Space Encapsulation in NJ & PA');
});

it('degrades to the primary state when even the short form would overflow', function () {
    $long = str_repeat('a', 54); // 54 + " in NJ & PA" (11) = 65 > 60; " in NJ" (6) = 60 ok
    expect(ServiceAreaTitle::qualify($long, 'New Jersey & Eastern Pennsylvania', ['NJ', 'PA']))
        ->toBe($long.' in NJ');
});

it('leaves the title clean when no region form fits', function () {
    $long = str_repeat('a', 58);
    expect(ServiceAreaTitle::qualify($long, 'New Jersey', ['NJ']))->toBe($long);
});

it('is idempotent — a title that already names a state (abbrev or name) is untouched', function () {
    expect(ServiceAreaTitle::qualify('Sump Pump Repair in NJ', 'New Jersey & Eastern Pennsylvania', ['NJ', 'PA']))
        ->toBe('Sump Pump Repair in NJ')
        ->and(ServiceAreaTitle::qualify('Basement Waterproofing in New Jersey', 'New Jersey & Eastern Pennsylvania', ['NJ', 'PA']))
        ->toBe('Basement Waterproofing in New Jersey')
        ->and(ServiceAreaTitle::qualify('Waterproofing across Pennsylvania', 'New Jersey & Eastern Pennsylvania', ['NJ', 'PA']))
        ->toBe('Waterproofing across Pennsylvania');
});

it('does not false-match a state abbrev inside a word (PA in "Repair")', function () {
    expect(ServiceAreaTitle::qualify('Sump Pump Repair', 'New Jersey', ['NJ', 'PA']))
        ->toBe('Sump Pump Repair in New Jersey');
});

it('handles a single-state tenant and empty inputs', function () {
    expect(ServiceAreaTitle::qualify('Drain Cleaning', 'Texas', ['TX']))->toBe('Drain Cleaning in Texas')
        ->and(ServiceAreaTitle::qualify('Drain Cleaning', '', []))->toBe('Drain Cleaning')
        ->and(ServiceAreaTitle::qualify('', 'New Jersey', ['NJ']))->toBe('');
});

it('peels the region out of a "brand tagline · region" slot instead of falling back to abbreviations', function () {
    // SPG's actual home service_area slot — before, the whole tagline was too long so it degraded to "NJ & PA".
    $slot = 'Sump Pump & Basement Water Specialists · New Jersey & Eastern Pennsylvania';
    expect(ServiceAreaTitle::qualify('Mold Testing', $slot, ['PA', 'MD', 'NJ']))
        ->toBe('Mold Testing in New Jersey & Eastern Pennsylvania');
});

it('decodes HTML entities in the region slot', function () {
    $slot = 'Specialists · New Jersey &amp; Eastern Pennsylvania';
    expect(ServiceAreaTitle::qualify('Mold Testing', $slot, ['NJ', 'PA']))
        ->toBe('Mold Testing in New Jersey & Eastern Pennsylvania');
});

it('does not split hyphenated place names (the ASCII hyphen is not a separator)', function () {
    expect(ServiceAreaTitle::qualify('Drain Cleaning', 'Plumbers · Wilkes-Barre & Scranton', ['PA']))
        ->toBe('Drain Cleaning in Wilkes-Barre & Scranton');
});
