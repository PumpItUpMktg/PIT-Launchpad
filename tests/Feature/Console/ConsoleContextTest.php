<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\OpsConsole\ConsoleContext;

function ilbAccountSite(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->create(['account_id' => $account->id]);

    return [$account, $site];
}

it('scopes a Site Admin to only their membership site', function () {
    [$account, $mine] = ilbAccountSite();
    [, $foreign] = ilbAccountSite();

    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $mine->id, 'role' => 'site_admin']);

    $ctx = app(ConsoleContext::class);

    expect($ctx->sites($siteAdmin)->pluck('id')->all())->toBe([$mine->id])
        ->and($ctx->current($siteAdmin)?->id)->toBe($mine->id)
        // A forged/foreign selection is refused and falls back to the permitted site.
        ->and($ctx->select($siteAdmin, $foreign->id))->toBeFalse()
        ->and($ctx->current($siteAdmin, $foreign->id)?->id)->toBe($mine->id);
});

it('gives a Site Admin with no memberships no sites at all (never the portfolio)', function () {
    ilbAccountSite(); // a site exists, but not theirs
    $siteAdmin = User::factory()->siteAdmin()->create();

    expect($siteAdmin->permittedSiteIds())->toBe([])
        ->and(app(ConsoleContext::class)->sites($siteAdmin)->all())->toBe([])
        ->and(app(ConsoleContext::class)->current($siteAdmin))->toBeNull();
});

it('lets a Super Admin see and select across the whole portfolio', function () {
    [, $a] = ilbAccountSite();
    [, $b] = ilbAccountSite();

    $superAdmin = User::factory()->admin()->create(); // unrestricted
    $ctx = app(ConsoleContext::class);

    expect($ctx->sites($superAdmin)->pluck('id')->all())->toContain($a->id, $b->id)
        ->and($ctx->select($superAdmin, $b->id))->toBeTrue()
        ->and($ctx->current($superAdmin)?->id)->toBe($b->id);
});

it('defaults to the tenant the operator is working on in the cockpit, never the alphabetically-first site', function () {
    [, $first] = ilbAccountSite();
    [, $working] = ilbAccountSite();
    $first->update(['brand_name' => 'AAA First Alphabetically']);
    $working->update(['brand_name' => 'Sump Pump Gurus']);

    $superAdmin = User::factory()->admin()->create();
    $ctx = app(ConsoleContext::class);

    // No console selection yet, no cockpit tenant: the first site (as before).
    expect($ctx->current($superAdmin)?->id)->toBe($first->id);

    // Logged into a tenant in /admin, first visit to a console page: THAT tenant, not the first one.
    app(ActiveTenant::class)->set($working->id);
    session()->forget(ConsoleContext::SESSION_KEY);
    expect($ctx->current($superAdmin)?->id)->toBe($working->id);

    // An explicit console selection still wins over the cockpit tenant.
    $ctx->select($superAdmin, $first->id);
    expect($ctx->current($superAdmin)?->id)->toBe($first->id);
});

it('ignores a cockpit tenant the user may not see', function () {
    [$account, $mine] = ilbAccountSite();
    [, $foreign] = ilbAccountSite();
    $siteAdmin = User::factory()->siteAdmin()->create();
    Membership::create(['user_id' => $siteAdmin->id, 'account_id' => $account->id, 'site_id' => $mine->id, 'role' => 'site_admin']);

    app(ActiveTenant::class)->set($foreign->id);
    expect(app(ConsoleContext::class)->current($siteAdmin)?->id)->toBe($mine->id);
});
