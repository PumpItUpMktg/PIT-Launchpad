<?php

use App\Operator\DeployLag;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

it('probes and stores a deploy-lag snapshot for the lobby to read', function () {
    Cache::forget('deploy_lag');

    // The suite runs inside the repo (a git checkout), so compute() resolves HEAD.
    $code = Artisan::call('launchpad:check-deploy-lag');

    expect($code)->toBe(0)
        ->and(app(DeployLag::class)->stored())->not->toBeNull()      // a snapshot is now cached
        ->and(app(DeployLag::class)->stored())->toHaveKey('severity'); // with the derived severity
});
