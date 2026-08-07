<?php

use App\Enums\UserRole;
use App\Filament\Console\Pages\UsersAccess;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;

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
