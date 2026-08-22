<?php

use App\Enums\UserRole;
use App\Filament\Resources\SiteResource\Pages\ListSites;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('lets a platform super-user jump to a tenant\'s client dashboard', function () {
    config(['launchpad.super_users' => ['boss@example.com']]);
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com', 'role' => UserRole::Operator]));
    $site = Site::factory()->create();

    Livewire::test(ListSites::class)
        ->assertTableActionVisible('viewClientDashboard', $site)
        ->callTableAction('viewClientDashboard', $site)
        ->assertRedirect('/portal');

    expect(session('client_site_id'))->toBe($site->id);
});

it('hides the button from a non-super-user operator', function () {
    config(['launchpad.super_users' => []]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create();

    Livewire::test(ListSites::class)->assertTableActionHidden('viewClientDashboard', $site);
});
