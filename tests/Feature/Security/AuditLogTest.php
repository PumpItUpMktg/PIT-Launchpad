<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Site;
use App\Security\Audit;

test('the audit recorder writes a row with target and metadata', function () {
    $site = Site::factory()->create();
    $connection = Connection::factory()->create(['site_id' => $site->id, 'provider' => 'gbp']);

    $log = (new Audit)->log(AuditAction::CredentialRevealed, $connection, 'actor-1', ['provider' => 'gbp']);

    expect($log->action)->toBe(AuditAction::CredentialRevealed)
        ->and($log->actor_id)->toBe('actor-1')
        ->and($log->target_type)->toBe($connection->getMorphClass())
        ->and($log->target_id)->toBe($connection->id)
        ->and($log->metadata)->toBe(['provider' => 'gbp'])
        ->and($log->created_at)->not->toBeNull();
});

test('an audit log cannot be updated', function () {
    $log = AuditLog::factory()->create();

    expect(fn () => $log->update(['action' => AuditAction::SiteWentLive->value]))
        ->toThrow(RuntimeException::class, 'append-only');
});

test('an audit log cannot be deleted', function () {
    $log = AuditLog::factory()->create();

    expect(fn () => $log->delete())->toThrow(RuntimeException::class, 'append-only');

    expect(AuditLog::count())->toBe(1);
});
