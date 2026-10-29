<?php

use App\Enums\UserRole;
use App\Filament\Console\Pages\UsersAccess;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\TechDevice;
use App\Models\User;
use Livewire\Livewire;

it('is reachable only by a Super Admin', function () {
    $this->actingAs(User::factory()->create());
    expect(UsersAccess::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->siteAdmin()->create());
    expect(UsersAccess::canAccess())->toBeFalse();
});

it('creates a Site Admin and ties them to a site', function () {
    $this->actingAs(User::factory()->create());
    $account = Account::factory()->create();
    $site = Site::factory()->create(['account_id' => $account->id]);

    $page = new UsersAccess;
    $page->newName = 'Dana Client';
    $page->newEmail = 'Dana@Example.com';
    $page->newRole = 'site_admin';
    $page->newSiteId = $site->id;
    $page->createUser();

    $created = User::query()->where('email', 'dana@example.com')->first();
    expect($created)->not->toBeNull()
        ->and($created->role)->toBe(UserRole::SiteAdmin)
        ->and(Membership::query()->where('user_id', $created->id)->where('site_id', $site->id)->exists())->toBeTrue()
        // The new Site Admin is correctly scoped to just that site.
        ->and($created->permittedSiteIds())->toBe([$site->id]);
});

it('provisions a tech (role=tech user + capture device) and surfaces the capture link + code', function () {
    $this->actingAs(User::factory()->create());
    $site = Site::factory()->create();

    $page = new UsersAccess;
    $page->newName = 'Mike Tech';
    $page->newEmail = '';                 // techs need no email
    $page->newRole = UserRole::Tech->value;
    $page->newSiteId = $site->id;
    $page->createUser();

    $device = TechDevice::withoutGlobalScopes()->where('site_id', $site->id)->first();

    expect($device)->not->toBeNull()
        ->and($device->user_id)->not->toBeNull()
        ->and($device->user->role)->toBe(UserRole::Tech)
        ->and($page->lastIssued['code'])->toMatch('/^\d{6}$/')
        ->and($page->lastIssued['link'])->toContain('/capture?device='.$device->id)
        ->and($page->newName)->toBe('');  // form cleared
});

it('will not provision a tech without a site', function () {
    $this->actingAs(User::factory()->create());

    $page = new UsersAccess;
    $page->newName = 'No Site Tech';
    $page->newRole = UserRole::Tech->value;
    $page->newSiteId = null;
    $page->createUser();

    expect(User::query()->where('role', UserRole::Tech->value)->exists())->toBeFalse()
        ->and($page->lastIssued)->toBeNull();
});

it('rejects a duplicate email', function () {
    $this->actingAs(User::factory()->create());
    User::factory()->create(['email' => 'taken@example.com']);

    $page = new UsersAccess;
    $page->newName = 'Someone';
    $page->newEmail = 'taken@example.com';
    $page->newRole = 'site_admin';
    $page->createUser();

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1);
});

it('changes a user\'s role', function () {
    $this->actingAs(User::factory()->create());
    $target = User::factory()->siteAdmin()->create();

    (new UsersAccess)->setRole($target->id, UserRole::Operator->value);

    expect($target->fresh()->role)->toBe(UserRole::Operator);
});

it('does nothing when a Site Admin tries to manage users', function () {
    $this->actingAs(User::factory()->siteAdmin()->create());
    $target = User::factory()->client()->create();

    (new UsersAccess)->setRole($target->id, UserRole::Operator->value);

    expect($target->fresh()->role)->toBe(UserRole::Client);
});

it('manages the whole tech lifecycle from the one page: resend, revoke, reactivate, move, remove', function () {
    $this->actingAs(User::factory()->create());
    $gurus = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    $other = Site::factory()->create(['brand_name' => 'Acme Roofing']);
    $device = TechDevice::factory()->create(['site_id' => $other->id, 'name' => 'Mike R.', 'email' => '']);
    $page = new UsersAccess;

    $page->refreshTech($device->id);
    expect($page->lastIssued['code'])->toMatch('/^\d{6}$/')
        ->and($page->lastIssued['site'])->toBe('Acme Roofing')   // the panel says which client the device is on
        ->and($device->refresh()->login_code_hash)->not->toBeNull();

    $page->moveTech($device->id, $gurus->id);
    expect($device->refresh()->site_id)->toBe($gurus->id);

    $page->removeTech($device->id);   // refused: still live
    expect(TechDevice::withoutGlobalScopes()->whereKey($device->id)->exists())->toBeTrue();

    $page->revokeTech($device->id);
    expect($device->refresh()->revoked_at)->not->toBeNull();

    $page->reactivateTech($device->id);
    expect($device->refresh()->revoked_at)->toBeNull()->and($page->lastIssued['site'])->toBe('Sump Pump Gurus');

    $page->revokeTech($device->id);
    $page->removeTech($device->id);
    expect(TechDevice::withoutGlobalScopes()->whereKey($device->id)->exists())->toBeFalse();
});

it('does nothing to a tech when a Site Admin cannot see that client', function () {
    $this->actingAs(User::factory()->siteAdmin()->create()); // no memberships → sees nothing
    $device = TechDevice::factory()->create(['site_id' => Site::factory()->create()->id]);

    (new UsersAccess)->revokeTech($device->id);

    expect($device->refresh()->revoked_at)->toBeNull();
});

it('renders the roster grouped by client with the status badge, and the old Capture Devices URL lands here', function () {
    $this->actingAs(User::factory()->create());
    $gurus = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);
    TechDevice::factory()->create(['site_id' => $gurus->id, 'name' => 'Mike R.']);
    TechDevice::factory()->revoked()->create(['site_id' => $gurus->id, 'name' => 'Old Mike']);

    Livewire::test(UsersAccess::class)
        ->assertOk()
        ->assertSee('Technicians (2)')
        ->assertSee('Sump Pump Gurus')
        ->assertSee('Mike R.')
        ->assertSee('Invited')
        ->assertSee('Inactive')
        ->assertSee('Remove')
        ->assertSee('Team (');

    $this->get('/console/capture-devices')->assertRedirect('/console/users');
});
