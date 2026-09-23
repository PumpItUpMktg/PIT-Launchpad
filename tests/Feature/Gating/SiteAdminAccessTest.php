<?php

use App\Enums\UserRole;
use App\Filament\Pages\BrandBoard;
use App\Filament\Pages\JobsBoard;
use App\Filament\Pages\Lobby;
use App\Filament\Pages\Operate\RebuildReadiness;
use App\Filament\Pages\QueueBoard;
use App\Filament\Pages\UsersBoard;
use App\Filament\Resources\ConnectionsResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\SiteResource;
use App\Filament\Resources\SourceResource;
use App\Filament\Resources\VoiceProfileResource;
use App\Http\Middleware\EnsureTenantSelected;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Nav\ConsoleNav;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/** A client-side Site Admin granted the given sites (the shape the Users board's "Grant access" creates). */
function siteAdminFor(array $siteIds = []): User
{
    $user = User::factory()->create(['role' => UserRole::SiteAdmin]);
    foreach ($siteIds as $siteId) {
        $site = Site::withoutGlobalScopes()->find($siteId);
        Membership::create(['user_id' => $user->id, 'account_id' => $site->account_id, 'site_id' => $siteId, 'role' => 'site_admin']);
    }

    return $user;
}

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('a Site Admin reaches the admin panel; a Client still never does', function () {
    $panel = Filament::getPanel('admin');

    expect(siteAdminFor()->canAccessPanel($panel))->toBeTrue()
        ->and(User::factory()->create(['role' => UserRole::Client])->canAccessPanel($panel))->toBeFalse()
        ->and(User::factory()->create(['role' => UserRole::Operator])->canAccessPanel($panel))->toBeTrue();
});

it('a Site Admin sees only their membership sites — none at all without a grant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    expect(siteAdminFor()->permittedSiteIds())->toBe([]);

    $scoped = siteAdminFor([$a->id]);
    expect($scoped->permittedSiteIds())->toBe([$a->id])
        ->and($scoped->canSeeSite($a))->toBeTrue()
        ->and($scoped->canSeeSite($b))->toBeFalse();

    $this->actingAs($scoped);
    expect(Site::query()->pluck('id')->all())->toBe([$a->id]); // the visibility scope: b does not exist for them
});

it('the platform super-user stays unrestricted even when granted a site', function () {
    config(['launchpad.super_users' => ['owner@example.com']]);
    $a = Site::factory()->create();
    Site::factory()->create();
    $owner = User::factory()->create(['role' => UserRole::Operator, 'email' => 'owner@example.com']);
    Membership::create(['user_id' => $owner->id, 'account_id' => $a->account_id, 'site_id' => $a->id, 'role' => 'operator']);

    expect($owner->permittedSiteIds())->toBeNull()
        ->and($owner->isSuperAdmin())->toBeTrue();
});

it('a single-site Site Admin is auto-locked into their site; a foreign ?site is refused', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $user = siteAdminFor([$a->id]);

    $request = Request::create('/admin/operate/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));
    $response = (new EnsureTenantSelected(new ActiveTenant))->handle($request, fn () => response('ok'));
    expect($response->getContent())->toBe('ok')
        ->and(session(ActiveTenant::SESSION_KEY))->toBe($a->id);

    $foreign = Request::create('/admin/operate/dashboard', 'GET', ['site' => $b->id]);
    $foreign->setUserResolver(fn () => $user);
    $refused = (new EnsureTenantSelected(new ActiveTenant))->handle($foreign, fn () => response('ok'));
    expect($refused->getStatusCode())->toBe(302);
});

it('a Site Admin opens the operate surfaces but none of the system ones', function () {
    $a = Site::factory()->create();
    $this->actingAs(siteAdminFor([$a->id]));

    expect(PageResource::canAccess())->toBeTrue()
        ->and(JobsBoard::canAccess())->toBeTrue()
        ->and(BrandBoard::canAccess())->toBeTrue()
        ->and(Lobby::canAccess())->toBeTrue()
        ->and(SiteResource::canCreate())->toBeFalse()
        ->and(UsersBoard::canAccess())->toBeFalse()
        ->and(QueueBoard::canAccess())->toBeFalse()
        ->and(ConnectionsResource::canAccess())->toBeFalse()
        ->and(SourceResource::canAccess())->toBeFalse()
        ->and(VoiceProfileResource::canAccess())->toBeFalse()
        ->and(RebuildReadiness::canAccess())->toBeFalse();
});

it('the Admin role opens every surface a plain operator does — the exact-role checks are gone', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(Lobby::canAccess())->toBeTrue()
        ->and(JobsBoard::canAccess())->toBeTrue()
        ->and(UsersBoard::canAccess())->toBeTrue()
        ->and(QueueBoard::canAccess())->toBeTrue()
        ->and(ConnectionsResource::canAccess())->toBeTrue()
        ->and(SiteResource::canCreate())->toBeTrue();
});

it('the header hides the system surfaces a Site Admin cannot open, and keeps them for an operator', function () {
    $a = Site::factory()->create();
    $labels = fn (): array => collect(app(ConsoleNav::class)->columns())
        ->mapWithKeys(fn (array $col): array => [$col['group'] => collect($col['items'])->pluck('label')->all()])
        ->all();

    $this->actingAs(siteAdminFor([$a->id]));
    $forSiteAdmin = $labels();
    expect($forSiteAdmin['Build'])->toContain('Pages')
        ->and($forSiteAdmin['System'] ?? [])->toBe(['Brand'])
        ->and(array_keys($forSiteAdmin))->toBe(['Build', 'Territory', 'Results', 'System']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    expect($labels()['System'])->toBe(['Connections', 'Feeds', 'Brand', 'Voice', 'Users', 'Queue', 'Recover', 'Corrections']);
});
