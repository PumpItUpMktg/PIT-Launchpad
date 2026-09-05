<?php

use App\Enums\UserRole;
use App\Filament\Pages\UsersBoard;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\Access\TenantUsers;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

afterEach(fn () => CurrentSite::clear());

function usersOperator(): User
{
    return User::factory()->create(['role' => UserRole::Operator]);
}

it('is operator-only', function () {
    expect(UsersBoard::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(UsersBoard::canAccess())->toBeFalse();

    $this->actingAs(usersOperator());
    expect(UsersBoard::canAccess())->toBeTrue();
});

it('lists only memberships that reach the locked site — never another tenant (no global user list)', function () {
    $this->actingAs(usersOperator());
    $accA = Account::factory()->create();
    $a = Site::factory()->for($accA)->create();
    $accB = Account::factory()->create();
    $b = Site::factory()->for($accB)->create();

    $ua = User::factory()->create(['name' => 'Alice A', 'email' => 'alice@a.example', 'role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $ua->id, 'account_id' => $accA->id, 'site_id' => $a->id, 'role' => UserRole::SiteAdmin]);
    $ub = User::factory()->create(['name' => 'Bob B', 'email' => 'bob@b.example', 'role' => UserRole::Client]);
    Membership::create(['user_id' => $ub->id, 'account_id' => $accB->id, 'site_id' => $b->id, 'role' => UserRole::Client]);
    // An account-wide grant on A's account reaches A too.
    $uw = User::factory()->create(['name' => 'Wide A', 'email' => 'wide@a.example', 'role' => UserRole::Client]);
    Membership::create(['user_id' => $uw->id, 'account_id' => $accA->id, 'site_id' => null, 'role' => UserRole::Client]);

    $board = app(TenantUsers::class)->for($a->id);
    $byEmail = collect($board['users'])->keyBy('email');

    expect($byEmail->keys()->all())->toContain('alice@a.example')->toContain('wide@a.example')
        ->and($byEmail->has('bob@b.example'))->toBeFalse()               // B's member never appears
        ->and($byEmail['alice@a.example']['scope'])->toBe('site')
        ->and($byEmail['wide@a.example']['scope'])->toBe('account')
        ->and($byEmail['alice@a.example']['revocable'])->toBeTrue()
        ->and($byEmail['wide@a.example']['revocable'])->toBeFalse();     // account-wide is not revoked here
});

it('grants a new user access to the locked tenant with no site picker', function () {
    $this->actingAs(usersOperator());
    $acc = Account::factory()->create();
    $site = Site::factory()->for($acc)->create();
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(UsersBoard::class)->call('grant', 'New Client', 'new@client.example', UserRole::Client->value);

    $user = User::query()->where('email', 'new@client.example')->first();
    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(UserRole::Client)
        ->and(Membership::query()->where('user_id', $user->id)->where('account_id', $acc->id)->where('site_id', $site->id)->exists())->toBeTrue();
});

it('grants an existing user without changing their global role', function () {
    $this->actingAs(usersOperator());
    $acc = Account::factory()->create();
    $site = Site::factory()->for($acc)->create();
    app(ActiveTenant::class)->set($site->id);
    $existing = User::factory()->create(['email' => 'existing@x.example', 'role' => UserRole::SiteAdmin]);

    Livewire::test(UsersBoard::class)->call('grant', 'Existing', 'existing@x.example', UserRole::Client->value);

    expect($existing->fresh()->role)->toBe(UserRole::SiteAdmin) // untouched — role may span other tenants
        ->and(Membership::query()->where('user_id', $existing->id)->where('site_id', $site->id)->exists())->toBeTrue();
});

it('revokes only the locked tenant\'s grant — another tenant\'s access is untouched', function () {
    $this->actingAs(usersOperator());
    $accA = Account::factory()->create();
    $a = Site::factory()->for($accA)->create();
    $accB = Account::factory()->create();
    $b = Site::factory()->for($accB)->create();
    $user = User::factory()->create(['role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $user->id, 'account_id' => $accA->id, 'site_id' => $a->id, 'role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $user->id, 'account_id' => $accB->id, 'site_id' => $b->id, 'role' => UserRole::SiteAdmin]);

    app(ActiveTenant::class)->set($a->id);
    Livewire::test(UsersBoard::class)->call('revoke', $user->id);

    expect(Membership::query()->where('user_id', $user->id)->where('site_id', $a->id)->exists())->toBeFalse()
        ->and(Membership::query()->where('user_id', $user->id)->where('site_id', $b->id)->exists())->toBeTrue(); // B intact
});

it('changes a single-tenant user\'s role, but deflects a multi-tenant user', function () {
    $this->actingAs(usersOperator());
    $accA = Account::factory()->create();
    $a = Site::factory()->for($accA)->create();
    $accB = Account::factory()->create();
    $b = Site::factory()->for($accB)->create();
    app(ActiveTenant::class)->set($a->id);

    // Single-tenant user (A only) → role change applies.
    $solo = User::factory()->create(['role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $solo->id, 'account_id' => $accA->id, 'site_id' => $a->id, 'role' => UserRole::SiteAdmin]);
    Livewire::test(UsersBoard::class)->call('setRole', $solo->id, UserRole::Client->value);
    expect($solo->fresh()->role)->toBe(UserRole::Client);

    // Multi-tenant user (A and B) → deflected, role unchanged.
    $multi = User::factory()->create(['role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $multi->id, 'account_id' => $accA->id, 'site_id' => $a->id, 'role' => UserRole::SiteAdmin]);
    Membership::create(['user_id' => $multi->id, 'account_id' => $accB->id, 'site_id' => $b->id, 'role' => UserRole::SiteAdmin]);
    Livewire::test(UsersBoard::class)->call('setRole', $multi->id, UserRole::Client->value);
    expect($multi->fresh()->role)->toBe(UserRole::SiteAdmin); // untouched — belongs to more than one tenant
});

it('renders the tenant-locked board and no site picker', function () {
    $this->actingAs(usersOperator());
    $acc = Account::factory()->create();
    $site = Site::factory()->for($acc)->create();
    $user = User::factory()->create(['name' => 'Dana Client', 'email' => 'dana@here.example', 'role' => UserRole::Client]);
    Membership::create(['user_id' => $user->id, 'account_id' => $acc->id, 'site_id' => $site->id, 'role' => UserRole::Client]);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(UsersBoard::class)->assertOk()->html();

    expect($html)->toContain('Dana Client')
        ->and($html)->not->toContain('<select'); // access is the locked tenant's, never a page picker
});
