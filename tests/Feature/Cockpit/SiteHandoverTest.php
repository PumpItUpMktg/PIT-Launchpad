<?php

use App\Enums\AuditAction;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Site;
use App\Operator\Handover\SiteHandover;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecurityHarness;

function handover(): SiteHandover
{
    return app(SiteHandover::class);
}

test('stays-on-our-hosting handover marks Live through the gate and audits it', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    Connection::factory()->rotated()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    SecurityHarness::attestAllPlatformSecrets();

    $result = handover()->handoverStaying($site, 'operator-1');

    expect($result->launched)->toBeTrue()
        ->and($result->repointed)->toBeFalse()
        ->and($site->fresh()->status)->toBe(SiteStatus::Live);

    expect(AuditLog::where('action', AuditAction::SiteWentLive->value)->where('target_id', $site->id)->exists())
        ->toBeTrue();
});

test('the gate blocks Live until credentials are clean — site stays Active', function () {
    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    Connection::factory()->compromised()->create(['site_id' => $site->id, 'provider' => 'wp_app_password']);
    SecurityHarness::attestAllPlatformSecrets();

    $result = handover()->handoverStaying($site);

    expect($result->launched)->toBeFalse()
        ->and($result->isBlocked())->toBeTrue()
        ->and($result->gateResult?->failures())->not->toBeEmpty()
        // The single guarded path did NOT write Live.
        ->and($site->fresh()->status)->toBe(SiteStatus::Active);

    expect(AuditLog::where('action', AuditAction::SiteWentLive->value)->exists())->toBeFalse();
});

test('migrate-to-client-hosting re-points the connection, verifies, then marks Live', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response(['id' => 1], 200)]);

    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    $connection = Connection::factory()->compromised()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['base_url' => 'https://built.ourhost.test', 'username' => 'svc', 'app_password' => 'build-pass'],
    ]);
    SecurityHarness::attestAllPlatformSecrets();

    $result = handover()->handoverMigrating($site, 'https://client-host.test', 'fresh-pass', 'svc', 'operator-1');

    expect($result->launched)->toBeTrue()
        ->and($result->repointed)->toBeTrue()
        ->and($site->fresh()->status)->toBe(SiteStatus::Live);

    // Connection re-pointed to the new host with the fresh credential, no longer compromised.
    $fresh = $connection->fresh();
    expect($fresh->credentials['base_url'])->toBe('https://client-host.test')
        ->and($fresh->credentials['app_password'])->toBe('fresh-pass')
        ->and($fresh->compromised)->toBeFalse();

    // Verify-before-revoke pinged the NEW host with the NEW credential.
    Http::assertSent(fn ($r) => str_contains($r->url(), 'client-host.test')
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('svc:fresh-pass')));
});

test('a failed re-point verification aborts before any Live write or credential change', function () {
    Http::fake(['*/wp-json/wp/v2/users/me' => Http::response('', 401)]);

    $site = Site::factory()->create(['status' => SiteStatus::Active]);
    $connection = Connection::factory()->compromised()->create([
        'site_id' => $site->id,
        'provider' => 'wp_app_password',
        'credentials' => ['base_url' => 'https://built.ourhost.test', 'username' => 'svc', 'app_password' => 'build-pass'],
    ]);
    SecurityHarness::attestAllPlatformSecrets();

    $result = handover()->handoverMigrating($site, 'https://client-host.test', 'fresh-pass', 'svc');

    expect($result->launched)->toBeFalse()
        ->and($result->repointed)->toBeFalse()
        ->and($site->fresh()->status)->toBe(SiteStatus::Active);

    // verify-before-revoke: the old credential is untouched.
    expect($connection->fresh()->credentials['app_password'])->toBe('build-pass');
});
