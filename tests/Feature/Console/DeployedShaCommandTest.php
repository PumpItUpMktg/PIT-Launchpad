<?php

use Illuminate\Support\Facades\Artisan;

it('reports the deployed git SHA and completes read-only', function () {
    // The test suite runs inside the repo (a git checkout), so HEAD resolves.
    $code = Artisan::call('launchpad:deployed-sha');
    $out = Artisan::output();

    expect($code)->toBe(0)
        ->and($out)->toContain('Deployed revision')
        ->and($out)->toMatch('/[0-9a-f]{40}/'); // the full HEAD sha is printed
});
