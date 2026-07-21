<?php

use App\Publishing\PhoneNumber;

test('display formats US 10- and 11-digit numbers, leaves others verbatim', function () {
    expect(PhoneNumber::display('8777867834'))->toBe('(877) 786-7834')       // the hero-button bug: raw digits → human
        ->and(PhoneNumber::display('+18777867834'))->toBe('+1 (877) 786-7834')
        ->and(PhoneNumber::display('(908) 520-6660'))->toBe('(908) 520-6660') // already formatted → stable
        ->and(PhoneNumber::display('+44 20 7946 0958'))->toBe('+44 20 7946 0958') // international → untouched
        ->and(PhoneNumber::display(''))->toBeNull()
        ->and(PhoneNumber::display(null))->toBeNull();
});

test('tel is E.164-ish (digits + leading +) regardless of display formatting', function () {
    expect(PhoneNumber::tel('(877) 786-7834'))->toBe('tel:8777867834')
        ->and(PhoneNumber::tel('+1 (877) 786-7834'))->toBe('tel:+18777867834')
        ->and(PhoneNumber::tel(''))->toBeNull();
});
