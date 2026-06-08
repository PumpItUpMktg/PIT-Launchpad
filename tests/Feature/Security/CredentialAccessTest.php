<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Site;
use App\Models\User;
use App\Security\CredentialMasker;
use App\Security\CredentialRevealer;
use Illuminate\Auth\Access\AuthorizationException;

test('an operator reveal returns the plaintext and writes an audit row', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $site = Site::factory()->create();
    $connection = Connection::factory()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['password' => 'top-secret'],
    ]);

    $revealed = app(CredentialRevealer::class)->reveal($connection, $operator);

    expect($revealed)->toBe(['password' => 'top-secret']);

    $log = AuditLog::where('action', AuditAction::CredentialRevealed->value)->sole();
    expect($log->actor_id)->toBe($operator->id)
        ->and($log->target_id)->toBe($connection->id)
        // The secret itself is never recorded in the audit metadata.
        ->and(json_encode($log->metadata))->not->toContain('top-secret');
});

test('a client cannot reveal credentials and no audit row is written', function () {
    $client = User::factory()->create(['role' => UserRole::Client]);
    $site = Site::factory()->create();
    $connection = Connection::factory()->create(['site_id' => $site->id, 'provider' => 'gbp']);

    expect(fn () => app(CredentialRevealer::class)->reveal($connection, $client))
        ->toThrow(AuthorizationException::class);

    expect(AuditLog::count())->toBe(0);
});

test('the policy allows operators and denies clients', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $client = User::factory()->create(['role' => UserRole::Client]);
    $connection = Connection::factory()->create(['site_id' => Site::factory(), 'provider' => 'gbp']);

    expect($operator->can('reveal', $connection))->toBeTrue()
        ->and($operator->can('rotate', $connection))->toBeTrue()
        ->and($client->can('reveal', $connection))->toBeFalse()
        ->and($client->can('rotate', $connection))->toBeFalse();
});

test('the masker shows only dots plus the last four characters', function () {
    $masker = new CredentialMasker;

    expect($masker->mask('super-secret-token'))->toBe('••••oken')
        ->and($masker->mask('abcd'))->toBe('••••')
        ->and($masker->mask(''))->toBe('••••')
        ->and($masker->maskArray(['password' => 'longsecretvalue', 'nested' => ['k' => 'anothersecret']]))
        ->toBe(['password' => '••••alue', 'nested' => ['k' => '••••cret']]);
});
