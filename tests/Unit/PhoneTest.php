<?php

use App\Support\Phone;

it('normalizes a US 10-digit number to E.164', function () {
    expect(Phone::toE164('(512) 555-0142'))->toBe('+15125550142')
        ->and(Phone::toE164('512.555.0142'))->toBe('+15125550142')
        ->and(Phone::toE164('1-512-555-0142'))->toBe('+15125550142');
});

it('keeps an explicit international number', function () {
    expect(Phone::toE164('+44 20 7946 0958'))->toBe('+442079460958');
});

it('returns null for empty input', function () {
    expect(Phone::toE164(''))->toBeNull()
        ->and(Phone::toE164('   '))->toBeNull();
});

it('formats a US E.164 for display and passes others through', function () {
    expect(Phone::format('+15125550142'))->toBe('(512) 555-0142')
        ->and(Phone::format('+442079460958'))->toBe('+442079460958')
        ->and(Phone::format(null))->toBe('');
});
