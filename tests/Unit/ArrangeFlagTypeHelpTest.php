<?php

use App\Enums\ArrangeFlagType;

it('gives every arrange-flag kind plain-language help (what + accept + dismiss)', function () {
    foreach (ArrangeFlagType::cases() as $type) {
        $help = $type->help();

        expect($help)->toHaveKeys(['what', 'accept', 'dismiss'])
            ->and(trim($help['what']))->not->toBe('')
            ->and(trim($help['accept']))->not->toBe('')
            ->and(trim($help['dismiss']))->not->toBe('');
    }
});
