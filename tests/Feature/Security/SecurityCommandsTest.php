<?php

use App\Enums\PlatformSecret;
use App\Models\Connection;
use App\Models\PlatformSecretRotation;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

test('rotate-connection rotates a tenant credential via the command', function () {
    // §2 backs the verifier with a live WP ping (verify-before-revoke); fake it.
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200)]);

    $site = Site::factory()->create();
    $connection = Connection::factory()->compromised()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['base_url' => 'https://wp.test', 'username' => 'svc', 'password' => 'old'],
    ]);

    $this->artisan('launchpad:rotate-connection', [
        'site' => $site->id,
        'type' => 'wp_app_password',
        '--credentials' => json_encode(['password' => 'rotated-new']),
    ])->assertSuccessful();

    $fresh = $connection->fresh();
    expect($fresh->credentials)->toBe(['password' => 'rotated-new'])
        ->and($fresh->compromised)->toBeFalse();
});

test('rotate-connection fails cleanly for an unknown credential type', function () {
    $site = Site::factory()->create();

    $this->artisan('launchpad:rotate-connection', [
        'site' => $site->id,
        'type' => 'nope',
        '--credentials' => json_encode(['x' => 'y']),
    ])->assertFailed();
});

test('attest-platform-rotation records the attestation', function () {
    $this->artisan('launchpad:attest-platform-rotation', ['secret' => 'app_key'])
        ->assertSuccessful();

    expect(PlatformSecretRotation::where('platform_secret', PlatformSecret::AppKey->value)->exists())->toBeTrue();
});

test('attest-platform-rotation is idempotent (one row per secret)', function () {
    $this->artisan('launchpad:attest-platform-rotation', ['secret' => 'r2'])->assertSuccessful();
    $this->artisan('launchpad:attest-platform-rotation', ['secret' => 'r2'])->assertSuccessful();

    expect(PlatformSecretRotation::where('platform_secret', PlatformSecret::R2->value)->count())->toBe(1);
});

test('check-stale-connections runs and reports', function () {
    $site = Site::factory()->create();
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'wp_app_password', 'last_rotated_at' => now()->subDays(365),
    ]);

    $this->artisan('launchpad:check-stale-connections')
        ->assertSuccessful()
        ->expectsOutputToContain('overdue');
});
