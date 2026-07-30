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
});

test('an operator can delete a WordPress connection, and the removal is audited', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();
    $connection = Connection::factory()->create([
        'site_id' => $site->id, 'provider' => 'wp_app_password',
        'credentials' => ['base_url' => 'https://old-host.flywp.xyz', 'app_password' => 'secret'],
    ]);

    Livewire::test(ListConnections::class)->callTableAction('delete', $connection);

    expect(Connection::withoutGlobalScopes()->whereKey($connection->id)->exists())->toBeFalse();

    $log = AuditLog::where('action', AuditAction::ConnectionRemoved->value)->where('target_id', $connection->id)->first();
    expect($log)->not->toBeNull()
        // The audit records which connection/provider, never the secret.
        ->and($log->metadata['provider'])->toBe('wp_app_password')
        ->and(json_encode($log->metadata))->not->toContain('secret');
});

test('the delete action is hidden from a non-operator (client)', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    $connection = Connection::factory()->create(['site_id' => Site::factory(), 'provider' => 'wp_app_password']);

    Livewire::test(ListConnections::class)->assertTableActionHidden('delete', $connection);
});
