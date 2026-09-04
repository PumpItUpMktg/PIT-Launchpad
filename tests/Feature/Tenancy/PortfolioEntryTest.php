<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\OperateDashboard;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * With the per-page dropdowns gone (2a-2), the Portfolio "Work on this" action is the primary way into a
 * tenant (alongside the topbar switcher). Both route through the single writer, ActiveTenant::set().
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

afterEach(fn () => CurrentSite::clear());

it('the Portfolio "Work on this" action locks the tenant via ActiveTenant and lands on the dashboard', function () {
    $site = Site::factory()->create();

    Livewire::test(ListSites::class)
        ->callTableAction('selectTenant', $site)
        ->assertRedirect(OperateDashboard::getUrl());

    // The one writer set the session key AND drove CurrentSite (so the scope is live immediately).
    expect(session(ActiveTenant::SESSION_KEY))->toBe($site->id)
        ->and(CurrentSite::id())->toBe($site->id);
});

it('the selectTenant action exists on every Portfolio row', function () {
    Site::factory()->create();

    Livewire::test(ListSites::class)->assertTableActionExists('selectTenant');
});
