<?php

use App\Enums\ConnectionProvider;
use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\SiteStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Site;
use App\Publishing\Chrome\ChromeStaleness;
use App\Publishing\Chrome\SiteProfileAssembler;
use Illuminate\Support\Facades\DB;

/** A WordPress-connected (verified: non-compromised + rotated) tenant — chrome can be pushed to it. */
function chromeSite(SiteStatus $status = SiteStatus::Active): Site
{
    $site = Site::factory()->create(['status' => $status]);
    Connection::factory()->create([
        'site_id' => $site->id, 'provider' => ConnectionProvider::WpAppPassword,
        'compromised' => false, 'last_rotated_at' => now(),
    ]);

    return $site;
}

function freshFingerprint(Site $site): string
{
    return SiteProfileAssembler::fingerprint(app(SiteProfileAssembler::class)->assemble($site->fresh()));
}

it('flags a drifted site — synced, but the assembled profile no longer matches the pushed fingerprint', function () {
    $site = chromeSite();
    $site->markChromeSynced('a-hash-the-fresh-profile-will-never-equal');

    $svc = app(ChromeStaleness::class);
    expect($svc->isDrifted($site->fresh()))->toBeTrue()
        ->and($svc->neverSynced($site->fresh()))->toBeFalse(); // it HAS synced — that's drift, not never-synced
});

it('does not flag a freshly-synced site', function () {
    $site = chromeSite();
    $site->markChromeSynced(freshFingerprint($site));

    expect(app(ChromeStaleness::class)->isDrifted($site->fresh()))->toBeFalse();
});

it('reports never-synced separately from drift (connected, never pushed)', function () {
    $site = chromeSite(); // chrome_synced_at is null

    $svc = app(ChromeStaleness::class);
    expect($svc->neverSynced($site))->toBeTrue()
        ->and($svc->isDrifted($site))->toBeFalse(); // never-synced is NOT drift
});

it('does not flag a site with no WordPress connection as never-synced', function () {
    $bare = Site::factory()->create(['status' => SiteStatus::Active]); // no WP connection

    $svc = app(ChromeStaleness::class);
    expect($svc->neverSynced($bare))->toBeFalse()
        ->and($svc->isDrifted($bare))->toBeFalse();
});

it('the weekly sweep flags drifted actives, leaves fresh ones, and skips onboarding', function () {
    $drifted = chromeSite();
    $drifted->markChromeSynced('old-hash');
    $fresh = chromeSite();
    $fresh->markChromeSynced(freshFingerprint($fresh));
    $onboarding = chromeSite(SiteStatus::Onboarding);
    $onboarding->markChromeSynced('old-hash'); // drifted data, but onboarding → skipped

    $this->artisan('launchpad:check-stale-chrome')->assertSuccessful();

    expect($drifted->fresh()->chrome_stale)->toBeTrue()
        ->and($fresh->fresh()->chrome_stale)->toBeFalse()
        ->and($onboarding->fresh()->chrome_stale)->toBeFalse(); // sweep never touched it
});

it('marks chrome stale when a PAGE publishes, but not a post (event-driven, via ContentObserver)', function () {
    $site = chromeSite();
    $site->markChromeSynced('old-hash');                       // any page publish now drifts vs this hash
    DB::table('sites')->where('id', $site->id)->update(['chrome_stale' => false]); // prove the observer sets it

    // A POST publish never touches chrome.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Post, 'status' => ContentStatus::Published]);
    expect($site->fresh()->chrome_stale)->toBeFalse();

    // A PAGE publish recomputes → drift (fresh fingerprint ≠ 'old-hash') → flagged instantly.
    Content::factory()->create(['site_id' => $site->id, 'kind' => ContentKind::Page, 'status' => ContentStatus::Published]);
    expect($site->fresh()->chrome_stale)->toBeTrue();
});

it('a successful chrome sync clears the drift flag', function () {
    $site = chromeSite();
    DB::table('sites')->where('id', $site->id)->update(['chrome_stale' => true]);

    $site->markChromeSynced(freshFingerprint($site));

    expect($site->fresh()->chrome_stale)->toBeFalse();
});
