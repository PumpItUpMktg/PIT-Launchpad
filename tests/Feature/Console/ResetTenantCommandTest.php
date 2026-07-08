<?php

use App\Enums\SiteStatus;
use App\Models\Connection;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\SetupState;
use App\Models\Silo;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
use App\Models\VoiceProfile;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublishHarness;

/** A fully-onboarded tenant: WP connection + a published page + structure + territory + brand + wizard state. */
function populatedTenant(array $siteAttrs = []): Site
{
    $site = PublishHarness::site(); // site + wp_app_password Connection (base_url https://wp.apex.example)
    $site->forceFill(array_merge(['status' => SiteStatus::Active, 'tagline' => 'We fix it fast'], $siteAttrs))->save();

    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'toilet-replacement', 'wp_post_id' => 123, 'status' => 'published']);
    Content::factory()->page()->create(['site_id' => $site->id, 'slug' => 'about', 'wp_post_id' => null]); // unpublished — no WP delete
    Silo::factory()->create(['site_id' => $site->id]);
    Market::factory()->create(['site_id' => $site->id]);
    Service::factory()->create(['site_id' => $site->id]);
    Keyword::factory()->create(['site_id' => $site->id]);
    VoiceProfile::factory()->active()->create(['site_id' => $site->id, 'version' => 1]);
    SiteNarrative::factory()->create(['site_id' => $site->id]);
    SiteBranding::factory()->create(['site_id' => $site->id]);
    SetupState::query()->create(['site_id' => $site->id, 'current_step' => 7, 'approved' => true, 'launched' => true]);

    return $site;
}

function counts(string $siteId): array
{
    $n = fn (string $class) => $class::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->count();

    return [
        'content' => $n(Content::class), 'silos' => $n(Silo::class), 'markets' => $n(Market::class),
        'services' => $n(Service::class), 'voice' => $n(VoiceProfile::class), 'narrative' => $n(SiteNarrative::class),
        'connection' => $n(Connection::class), 'setup' => $n(SetupState::class),
    ];
}

it('cleans up WP BEFORE the wipe — force-deletes the published page via the plugin, then rewinds Launchpad', function () {
    Http::fake(['*/wp-json/launchpad/v1/content/delete' => Http::response(['deleted' => true], 200)]);
    $site = populatedTenant();

    $this->artisan('launchpad:reset-tenant', ['site' => $site->id, '--keep-wordpress' => true, '--force' => true])
        ->assertSuccessful();

    // The delete went out to the authed plugin endpoint keyed on the control-plane ULID — which proves
    // Content was read (ids intact) BEFORE the wipe, iron law #1. (Force-delete is server-side in the plugin.)
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_contains($r->url(), '/wp-json/launchpad/v1/content/delete')
        && ! empty($r['content_id']));
    // only the PUBLISHED page is touched (the unpublished /about has no wp_post_id → no call)
    Http::assertSentCount(1);

    $after = counts($site->id);
    expect($after['content'])->toBe(0)->and($after['silos'])->toBe(0)->and($after['markets'])->toBe(0)
        ->and($after['services'])->toBe(0)->and($after['setup'])->toBe(0);

    $fresh = Site::query()->find($site->id);
    expect($fresh)->not->toBeNull()                              // site record kept
        ->and($fresh->status)->toBe(SiteStatus::Onboarding)      // lifecycle rewound
        ->and($fresh->tagline)->toBeNull();                      // business field cleared
});

it('keeps the WP connection with --keep-wordpress, removes it without', function () {
    Http::fake(['*/wp-json/launchpad/v1/content/delete' => Http::response(['deleted' => true], 200)]);

    $kept = populatedTenant();
    $this->artisan('launchpad:reset-tenant', ['site' => $kept->id, '--keep-wordpress' => true, '--force' => true])->assertSuccessful();
    expect(counts($kept->id)['connection'])->toBe(1);           // kept

    $dropped = populatedTenant();
    $this->artisan('launchpad:reset-tenant', ['site' => $dropped->id, '--force' => true])->assertSuccessful();
    expect(counts($dropped->id)['connection'])->toBe(0)         // removed
        ->and(Site::query()->find($dropped->id)->domain_url)->toBeNull();
});

it('wipes brand by default, keeps it with --keep-brand', function () {
    Http::fake(['*/wp-json/launchpad/v1/content/delete' => Http::response(['deleted' => true], 200)]);

    $wiped = populatedTenant();
    $this->artisan('launchpad:reset-tenant', ['site' => $wiped->id, '--keep-wordpress' => true, '--force' => true])->assertSuccessful();
    expect(counts($wiped->id)['voice'])->toBe(0)->and(counts($wiped->id)['narrative'])->toBe(0);

    $kept = populatedTenant();
    $this->artisan('launchpad:reset-tenant', ['site' => $kept->id, '--keep-wordpress' => true, '--keep-brand' => true, '--force' => true])->assertSuccessful();
    expect(counts($kept->id)['voice'])->toBe(1)->and(counts($kept->id)['narrative'])->toBe(1)
        ->and(SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $kept->id)->count())->toBe(1);
});

it('refuses to reset a LIVE (client-handed-over) tenant', function () {
    $site = populatedTenant(['status' => SiteStatus::Live]);

    $this->artisan('launchpad:reset-tenant', ['site' => $site->id, '--keep-wordpress' => true, '--force' => true])
        ->expectsOutputToContain('Refusing to reset')
        ->assertFailed();

    expect(counts($site->id)['content'])->toBeGreaterThan(0); // nothing wiped
});

it('--dry-run touches nothing (no WP call, no deletes) but reports the plan', function () {
    Http::fake();
    $site = populatedTenant();
    $before = counts($site->id);

    $this->artisan('launchpad:reset-tenant', ['site' => $site->id, '--keep-wordpress' => true, '--dry-run' => true])
        ->expectsOutputToContain('would reset')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(counts($site->id))->toBe($before);
});

it('honours the named confirmation — answering no aborts the wipe', function () {
    Http::fake();
    $site = populatedTenant();

    $this->artisan('launchpad:reset-tenant', ['site' => $site->id, '--keep-wordpress' => true])
        ->expectsConfirmation('Proceed?', 'no')
        ->assertSuccessful();

    expect(counts($site->id)['content'])->toBeGreaterThan(0); // untouched
    Http::assertNothingSent();
});
