<?php

use App\Enums\PlatformSecret;
use App\Models\Connection;
use App\Models\PlatformSecretRotation;
use App\Models\Site;
use App\Security\SiteLaunchGate;
use Tests\Support\SecurityHarness;

test('a compromised credential blocks launch', function () {
    $site = Site::factory()->create();
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    SecurityHarness::attestAllPlatformSecrets();

    $result = (new SiteLaunchGate)->check($site);

    expect($result->passed)->toBeFalse()
        ->and($result->failures())->toHaveCount(1)
        ->and($result->failures()[0]->key)->toBe('connection:wp_app_password')
        ->and($result->failures()[0]->reason)->toContain('compromised');
});

test('all-clean credentials and platform attestations pass', function () {
    $site = Site::factory()->create();
    Connection::factory()->rotated()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    Connection::factory()->rotated()->create(['site_id' => $site->id, 'provider' => 'gbp']);
    SecurityHarness::attestAllPlatformSecrets();

    expect((new SiteLaunchGate)->check($site)->passed)->toBeTrue();
});

test('a missing platform attestation blocks launch even with clean connections', function () {
    $site = Site::factory()->create();
    Connection::factory()->rotated()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);

    // Attest every platform secret except APP_KEY.
    foreach (PlatformSecret::cases() as $secret) {
        if ($secret !== PlatformSecret::AppKey) {
            PlatformSecretRotation::factory()->secret($secret)->create();
        }
    }

    $result = (new SiteLaunchGate)->check($site);

    expect($result->passed)->toBeFalse()
        ->and($result->failures())->toHaveCount(1)
        ->and($result->failures()[0]->key)->toBe('platform:app_key');
});

test('a credential last rotated before exposure blocks launch', function () {
    $site = Site::factory()->create();
    Connection::factory()->create([
        'site_id' => $site->id,
        'provider' => 'gbp',
        'compromised' => false,
        'compromised_reason' => null,
        'exposed_at' => now(),
        'last_rotated_at' => now()->subWeek(),
    ]);
    SecurityHarness::attestAllPlatformSecrets();

    $result = (new SiteLaunchGate)->check($site);

    expect($result->passed)->toBeFalse()
        ->and($result->failures()[0]->reason)->toContain('exposed');
});

test('a credential never rotated blocks launch', function () {
    $site = Site::factory()->create();
    Connection::factory()->create([
        'site_id' => $site->id,
        'provider' => 'gbp',
        'compromised' => false,
        'compromised_reason' => null,
        'exposed_at' => null,
        'last_rotated_at' => null,
    ]);
    SecurityHarness::attestAllPlatformSecrets();

    $result = (new SiteLaunchGate)->check($site);

    expect($result->passed)->toBeFalse()
        ->and($result->failures()[0]->reason)->toContain('never');
});
