<?php

use App\Client\ClientAccess;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Security\Capability;
use Filament\Panel;

beforeEach(fn () => config(['launchpad.super_users' => ['pumpitupmktg@gmail.com']]));

function fakePanel(string $id): Panel
{
    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('getId')->andReturn($id);

    return $panel;
}

it('grants a configured super-user every capability and every panel, regardless of role', function () {
    // Deliberately the lowest role — the override must win.
    $user = User::factory()->create(['email' => 'PumpItUpMktg@gmail.com', 'role' => UserRole::Client]);

    expect($user->isPlatformSuperUser())->toBeTrue()
        ->and($user->hasCapability(Capability::ManageCredentials))->toBeTrue()
        ->and($user->hasCapability(Capability::ApproveContent))->toBeTrue()
        ->and($user->canAccessPanel(fakePanel('client')))->toBeTrue()
        ->and($user->canAccessPanel(fakePanel('console')))->toBeTrue()
        ->and($user->canAccessPanel(fakePanel('admin')))->toBeTrue();
});

it('does not elevate a normal user', function () {
    $user = User::factory()->client()->create(['email' => 'someone@else.com']);

    expect($user->isPlatformSuperUser())->toBeFalse()
        ->and($user->hasCapability(Capability::ManageCredentials))->toBeFalse()
        ->and($user->canAccessPanel(fakePanel('client')))->toBeTrue()
        ->and($user->canAccessPanel(fakePanel('console')))->toBeFalse();
});

it('lets a super-user see every client site in the portal', function () {
    $s1 = Site::factory()->create(['account_id' => Account::factory()->create()->id]);
    $s2 = Site::factory()->create(['account_id' => Account::factory()->create()->id]);
    $super = User::factory()->create(['email' => 'pumpitupmktg@gmail.com']);

    expect(app(ClientAccess::class)->sites($super)->pluck('id'))
        ->toContain($s1->id)
        ->toContain($s2->id);
});
