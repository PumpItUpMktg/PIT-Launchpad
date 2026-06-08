<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Site;
use App\Security\Audit;
use App\Security\ConnectionRotator;
use Tests\Support\StubConnectionVerifier;

test('a verified rotation swaps the credential, clears compromised, and audits', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->compromised()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['password' => 'old-secret'],
        'last_rotated_at' => null,
    ]);

    $rotator = new ConnectionRotator(new StubConnectionVerifier(true), new Audit);
    $result = $rotator->rotate($connection, ['password' => 'new-secret']);

    expect($result->ok)->toBeTrue();

    $fresh = $connection->fresh();
    expect($fresh->credentials)->toBe(['password' => 'new-secret'])
        ->and($fresh->compromised)->toBeFalse()
        ->and($fresh->last_rotated_at)->not->toBeNull()
        ->and(AuditLog::where('action', AuditAction::CredentialRotated->value)->count())->toBe(1);
});

test('verify-before-revoke: a failed verification leaves the old credential untouched', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->compromised()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['password' => 'old-secret'],
        'last_rotated_at' => null,
    ]);

    $verifier = new StubConnectionVerifier(false);
    $result = (new ConnectionRotator($verifier, new Audit))->rotate($connection, ['password' => 'new-secret']);

    expect($result->ok)->toBeFalse()
        ->and($verifier->calls)->toBe(1);

    // Nothing changed — still the old secret, still compromised, never rotated.
    $fresh = $connection->fresh();
    expect($fresh->credentials)->toBe(['password' => 'old-secret'])
        ->and($fresh->compromised)->toBeTrue()
        ->and($fresh->last_rotated_at)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

test('the default mock verifier accepts a non-empty credential', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => 'gbp']);

    $result = app(ConnectionRotator::class)->rotate($connection, ['token' => 'fresh']);

    expect($result->ok)->toBeTrue()
        ->and($connection->fresh()->compromised)->toBeFalse();
});
