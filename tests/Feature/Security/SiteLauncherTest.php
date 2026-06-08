<?php

use App\Enums\AuditAction;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Site;
use App\Security\SiteLauncher;
use Tests\Support\SecurityHarness;

test('launch is blocked and the site stays un-live while the gate fails', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    SecurityHarness::attestAllPlatformSecrets();

    $result = app(SiteLauncher::class)->launch($site);

    expect($result->passed)->toBeFalse()
        ->and($site->fresh()->status)->toBe(SiteStatus::Active)
        ->and(AuditLog::where('action', AuditAction::SiteWentLive->value)->count())->toBe(0);
});

test('a passing gate takes the site live and audits the transition', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    Connection::factory()->rotated()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    SecurityHarness::attestAllPlatformSecrets();

    $result = app(SiteLauncher::class)->launch($site, 'operator-9');

    expect($result->passed)->toBeTrue()
        ->and($site->fresh()->status)->toBe(SiteStatus::Live)
        ->and($site->fresh()->isLive())->toBeTrue();

    $log = AuditLog::where('action', AuditAction::SiteWentLive->value)->sole();
    expect($log->target_id)->toBe($site->id)
        ->and($log->actor_id)->toBe('operator-9');
});
