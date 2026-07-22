<?php

use Illuminate\Support\Facades\Artisan;

test('the shipped theme variation files are in sync with the enum (--check passes)', function () {
    // The theme's styles/*.json are generated from StyleVariation; --check fails if any drifted.
    expect(Artisan::call('launchpad:build-theme-variations', ['--check' => true]))->toBe(0);
});
