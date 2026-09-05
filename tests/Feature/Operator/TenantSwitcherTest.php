<?php

use App\Enums\UserRole;
use App\Filament\Pages\Lobby;
use App\Livewire\TenantSwitcher;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Livewire\Livewire;

// Tenant-lock remediation (shape E): the topbar has NO in-chrome switcher. Under a lock it shows the
// CURRENT tenant only, plus "Exit site" → Lobby. The old dropdown-of-every-tenant tests asserted the
// breach itself (other tenants' names in the chrome of every page) and are replaced by these.

it('shows the working tenant as a static chip — never another tenant the operator can reach', function () {
    // An operator who is a member of BOTH accounts (the real multi-tenant operator) still sees only the
    // LOCKED tenant in the chrome — Bravo never appears.
    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();
    $a = Site::factory()->for($accountA)->create(['brand_name' => 'Alpha']);
    $b = Site::factory()->for($accountB)->create(['brand_name' => 'Bravo']);

    $operator = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $operator->id, 'account_id' => $accountA->id, 'role' => UserRole::Operator]);
    Membership::create(['user_id' => $operator->id, 'account_id' => $accountB->id, 'role' => UserRole::Operator]);
    $this->actingAs($operator);
    app(ActiveTenant::class)->set($a->id);

    Livewire::test(TenantSwitcher::class)
        ->assertSee('Working on')
        ->assertSee('Alpha')
        ->assertDontSee('Bravo')     // no dropdown → the other tenant is never named in the chrome
        ->assertSee('Exit site');
});

it('Exit site returns to the Lobby (changing tenant is Exit → Lobby → enter)', function () {
    $site = Site::factory()->create(['brand_name' => 'Solo']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    app(ActiveTenant::class)->set($site->id);

    Livewire::test(TenantSwitcher::class)
        ->call('exitSite')
        ->assertRedirect(Lobby::getUrl());
});
