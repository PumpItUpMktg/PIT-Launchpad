<?php

use App\Publishing\PhoneNumber;

it('rewrites a raw prose phone into the canonical display format', function () {
    // The exact live-site defect: drafter quoted "+1 908-224-0550" into body copy.
    expect(PhoneNumber::canonicalizeInText('When you call us at +1 908-224-0550, you reach our team.'))
        ->toBe('When you call us at +1 (908) 224-0550, you reach our team.');
});

it('canonicalizes a bare 10-digit number and dotted/paren variants', function () {
    expect(PhoneNumber::canonicalizeInText('Call 908-224-0550 today'))->toBe('Call (908) 224-0550 today')
        ->and(PhoneNumber::canonicalizeInText('Reach us at 908.224.0550.'))->toBe('Reach us at (908) 224-0550.')
        ->and(PhoneNumber::canonicalizeInText('Dial 9082240550 now'))->toBe('Dial (908) 224-0550 now');
});

it('is idempotent on an already-canonical number', function () {
    $canonical = 'Call +1 (908) 224-0550 anytime';
    expect(PhoneNumber::canonicalizeInText($canonical))->toBe($canonical);

    $ten = 'Call (908) 224-0550 anytime';
    expect(PhoneNumber::canonicalizeInText($ten))->toBe($ten);
});

it('rewrites every occurrence in a multi-number paragraph', function () {
    $in = 'Call +1 908-224-0550 or the office line 908.555.1234 today.';
    expect(PhoneNumber::canonicalizeInText($in))
        ->toBe('Call +1 (908) 224-0550 or the office line (908) 555-1234 today.');
});

it('leaves non-phone numbers untouched', function () {
    foreach ([
        'We install a 1/3 HP pump within 24 hours.',
        'Serving the area since 2010-2024 with a 10-year warranty.',
        'Order #12345678901234 shipped.',
        'The invoice total was 1,299.00 dollars.',
        'Open 24/7, 365 days a year.',
    ] as $text) {
        expect(PhoneNumber::canonicalizeInText($text))->toBe($text);
    }
});

it('does not bite digits out of a longer run', function () {
    // 12 digits — not a phone; must stay intact (no 10-digit slice rewritten).
    expect(PhoneNumber::canonicalizeInText('SKU 123456789012 is in stock'))
        ->toBe('SKU 123456789012 is in stock');
});

it('handles null and empty input', function () {
    expect(PhoneNumber::canonicalizeInText(null))->toBe('')
        ->and(PhoneNumber::canonicalizeInText(''))->toBe('');
});
