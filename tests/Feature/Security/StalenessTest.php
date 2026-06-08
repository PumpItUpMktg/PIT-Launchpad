<?php

use App\Models\Connection;
use App\Models\Site;
use App\Security\ConnectionStaleness;

test('it flags credentials past their per-provider threshold and ones never rotated', function () {
    $site = Site::factory()->create();

    // Fresh — within threshold, not reported.
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'gbp', 'last_rotated_at' => now()->subDays(10),
    ]);
    // Overdue — gbp threshold is 180 days.
    $overdue = Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'ga4', 'last_rotated_at' => now()->subDays(200),
    ]);
    // Never rotated.
    $never = Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'ghl', 'last_rotated_at' => null,
    ]);

    $report = (new ConnectionStaleness)->report();

    expect($report)->toHaveCount(2);

    $ids = $report->map(fn ($s) => $s->connection->id)->all();
    expect($ids)->toContain($overdue->id)->toContain($never->id);

    $neverRow = $report->firstWhere(fn ($s) => $s->connection->id === $never->id);
    expect($neverRow->neverRotated())->toBeTrue();
});

test('the per-provider threshold is honored', function () {
    $site = Site::factory()->create();

    // wp_app_password threshold is 90 days; 100 days is overdue.
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'wp_app_password', 'last_rotated_at' => now()->subDays(100),
    ]);
    // A 100-day-old gbp credential is still within its 180-day threshold.
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'gbp', 'last_rotated_at' => now()->subDays(100),
    ]);

    $report = (new ConnectionStaleness)->report();

    expect($report)->toHaveCount(1)
        ->and($report->first()->connection->provider->value)->toBe('wp_app_password');
});
