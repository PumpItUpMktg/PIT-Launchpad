<?php

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;

test('changing a user role writes a RoleChanged audit row', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $user->update(['role' => UserRole::Client]);

    $log = AuditLog::where('action', AuditAction::RoleChanged->value)->sole();
    expect($log->target_id)->toBe($user->id)
        ->and($log->metadata['from'])->toBe('operator')
        ->and($log->metadata['to'])->toBe('client');
});

test('updating a user without touching the role writes no audit row', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $user->update(['name' => 'Renamed Operator']);

    expect(AuditLog::where('action', AuditAction::RoleChanged->value)->count())->toBe(0);
});
