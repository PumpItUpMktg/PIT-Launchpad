<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureTenantSelected;
use App\Models\Account;
use App\Models\Content;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

function operatorFor(array $siteIds = [], ?string $accountWide = null): User
{
    $user = User::factory()->create(['role' => UserRole::Operator]);
    foreach ($siteIds as $siteId) {
        $site = Site::withoutGlobalScopes()->find($siteId);
        Membership::create(['user_id' => $user->id, 'account_id' => $site->account_id, 'site_id' => $siteId, 'role' => 'operator']);
    }
    if ($accountWide !== null) {
        Membership::create(['user_id' => $user->id, 'account_id' => $accountWide, 'site_id' => null, 'role' => 'operator']);
    }

    return $user;
}

it('resolves permitted sites: admin=all, no-membership operator=all, membership operator=the set', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect($admin->permittedSiteIds())->toBeNull()          // unrestricted
        ->and($admin->canSeeSite($b))->toBeTrue();

    $fresh = User::factory()->create(['role' => UserRole::Operator]);
    expect($fresh->permittedSiteIds())->toBeNull();         // back-compat: no membership = unrestricted

    $scoped = operatorFor([$a->id]);
    expect($scoped->permittedSiteIds())->toBe([$a->id])
        ->and($scoped->canSeeSite($a))->toBeTrue()
        ->and($scoped->canSeeSite($b))->toBeFalse();
});

it('an account-wide membership grants every site under the account', function () {
    $account = Account::factory()->create();
    $a = Site::factory()->create(['account_id' => $account->id]);
    $b = Site::factory()->create(['account_id' => $account->id]);
    Site::factory()->create(); // a third, different account

    $user = operatorFor(accountWide: $account->id);

    expect($user->permittedSiteIds())->toHaveCount(2)
        ->and($user->canSeeSite($a))->toBeTrue()
        ->and($user->canSeeSite($b))->toBeTrue();
});

it('layer 1: the visibility scope limits Site queries to the permitted set', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    Site::factory()->create();

    $this->actingAs(operatorFor([$a->id, $b->id]));

    expect(Site::query()->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and(Site::query()->count())->toBe(2)
        ->and(Site::query()->find($a->id))->not->toBeNull();

    // A non-member site is invisible even by direct id.
    $other = Site::withoutGlobalScopes()->whereNotIn('id', [$a->id, $b->id])->first();
    expect(Site::query()->find($other->id))->toBeNull();
});

it('layer 1: cross-tenant aggregates only include the permitted sites rows', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    Content::factory()->create(['site_id' => $a->id]);
    Content::factory()->create(['site_id' => $b->id]);

    $this->actingAs(operatorFor([$a->id])); // member of A only

    // Cross-tenant (no working tenant set) → VisibleTenantScope keeps only A's rows.
    expect(Content::query()->pluck('site_id')->unique()->values()->all())->toBe([$a->id]);
});

it('layer 2: a URL-guessed non-member tenant is refused at the middleware', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $user = operatorFor([$a->id]);

    $request = Request::create('/admin/operate/dashboard', 'GET', ['site' => $b->id]);
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureTenantSelected(new ActiveTenant))->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('sites'); // → Portfolio, never renders
});

it('layer 2: a single-site operator auto-selects their one tenant (no forced picker)', function () {
    $a = Site::factory()->create();
    $user = operatorFor([$a->id]);

    $request = Request::create('/admin/operate/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    $response = (new EnsureTenantSelected(new ActiveTenant))->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok')                        // proceeded, not redirected
        ->and(session(ActiveTenant::SESSION_KEY))->toBe($a->id);        // auto-selected
});

it('a multi-site operator with no tenant is sent to the picker', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $user = operatorFor([$a->id, $b->id]);

    $request = Request::create('/admin/operate/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    $response = (new EnsureTenantSelected(new ActiveTenant))->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('sites');
});

it('admin reaches the operator panel; client never does', function () {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $client = User::factory()->create(['role' => UserRole::Client]);

    $panel = Filament::getPanel('admin');
    expect($admin->canAccessPanel($panel))->toBeTrue()
        ->and($client->canAccessPanel($panel))->toBeFalse();
});
