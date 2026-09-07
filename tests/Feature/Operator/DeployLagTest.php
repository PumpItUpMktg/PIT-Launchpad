<?php

use App\Operator\DeployLag;
use Illuminate\Support\Facades\Cache;

it('severity is age-driven: fresh / late / stale', function () {
    $lag = new DeployLag;

    expect($lag->severityFor(0, null))->toBe('fresh')                           // up to date
        ->and($lag->severityFor(3, now()->subHours(2)))->toBe('late')           // behind, oldest recent (<6h)
        ->and($lag->severityFor(3, now()->subHours(7)))->toBe('stale')          // behind, oldest older than cutoff
        ->and($lag->severityFor(2, null))->toBe('late');                        // behind, age unknown → not escalated
});

it('the lobby notice fires ONLY when stale, re-derived live from the stored oldest timestamp', function () {
    $lag = new DeployLag;

    // Stale: behind + the oldest undeployed commit is 8h old.
    Cache::forever('deploy_lag', [
        'behind' => 11, 'oldest_undeployed_at' => now()->subHours(8)->toIso8601String(),
        'deployed_short' => 'abc1234', 'checked_at' => now()->toIso8601String(),
    ]);
    $n = $lag->notice();
    expect($n)->not->toBeNull()
        ->and($n['behind'])->toBe(11)
        ->and($n['oldest_hours'])->toBeGreaterThanOrEqual(8)
        ->and($n['deployed_short'])->toBe('abc1234');

    // Late (behind but recent) → no platform notice (a deploy may be in flight).
    Cache::forever('deploy_lag', ['behind' => 1, 'oldest_undeployed_at' => now()->subHours(2)->toIso8601String()]);
    expect($lag->notice())->toBeNull();

    // Fresh, and never-checked → no notice.
    Cache::forever('deploy_lag', ['behind' => 0, 'oldest_undeployed_at' => null]);
    expect($lag->notice())->toBeNull();
    Cache::forget('deploy_lag');
    expect($lag->notice())->toBeNull();
});
