<?php

use App\Enums\UserRole;
use App\Livewire\TenantSwitcher;
use App\Models\Membership;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use Livewire\Livewire;

it('lists the accessible tenants and switching sets the session tenant', function () {
    $a = Site::factory()->create(['brand_name' => 'Alpha']);
    $b = Site::factory()->create(['brand_name' => 'Bravo']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    app(ActiveTenant::class)->set($a->id);

    Livewire::test(TenantSwitcher::class)
        ->assertSet('single', false)                 // >1 tenant → real switcher
        ->assertSee('Alpha')->assertSee('Bravo')
        ->assertSee('Go to Portfolio')
        ->call('switchTenant', $b->id)
        ->assertRedirect();

    expect(session(ActiveTenant::SESSION_KEY))->toBe($b->id);
});

it('is a static chip for a single-site operator (no dropdown)', function () {
    $a = Site::factory()->create(['brand_name' => 'Solo']);
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $operator->id, 'account_id' => $a->account_id, 'site_id' => $a->id, 'role' => 'operator']);
    $this->actingAs($operator);
    app(ActiveTenant::class)->set($a->id);

    Livewire::test(TenantSwitcher::class)
        ->assertSet('single', true)                  // one accessible tenant → static
        ->assertDontSee('Go to Portfolio')
        ->assertSee('Solo');
});

it('refuses to switch to a tenant the operator cannot see', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create(); // non-member
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    Membership::create(['user_id' => $operator->id, 'account_id' => $a->account_id, 'site_id' => $a->id, 'role' => 'operator']);
    $this->actingAs($operator);
    app(ActiveTenant::class)->set($a->id);

    // Even calling the action directly with a non-member id is refused.
    Livewire::test(TenantSwitcher::class)->call('switchTenant', $b->id);

    expect(session(ActiveTenant::SESSION_KEY))->toBe($a->id); // unchanged — non-member refused
});
