<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Filament\Resources\ConnectionsResource\Pages\ListConnections;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

test('the reveal action returns plaintext and writes an audited row (wired to §9)', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'gbp', 'credentials' => ['token' => 'super-secret'],
    ]);

    Livewire::test(ListConnections::class)->callTableAction('reveal', $connection);

    $log = AuditLog::where('action', AuditAction::CredentialRevealed->value)->where('target_id', $connection->id)->first();
    expect($log)->not->toBeNull()
        // The audit records which connection, never the secret itself.
        ->and(json_encode($log->metadata))->not->toContain('super-secret');
});

test('the rotate action rotates the credential via §9 verify-before-revoke', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->compromised()->create([
        'site_id' => $site->id, 'provider' => 'gbp', 'credentials' => ['token' => 'old-token'],
    ]);

    Livewire::test(ListConnections::class)
        ->callTableAction('rotate', $connection, ['credentials' => ['token' => 'new-token']]);

    $fresh = $connection->fresh();
    expect($fresh->credentials)->toBe(['token' => 'new-token'])
        ->and($fresh->compromised)->toBeFalse()
        ->and($fresh->last_rotated_at)->not->toBeNull();

    expect(AuditLog::where('action', AuditAction::CredentialRotated->value)->where('target_id', $connection->id)->exists())
        ->toBeTrue();
});

test('the connections control renders for an operator', function () {
    Connection::factory()->create(['site_id' => Site::factory(), 'provider' => 'wp_app_password']);

    Livewire::test(ListConnections::class)->assertOk();
});
