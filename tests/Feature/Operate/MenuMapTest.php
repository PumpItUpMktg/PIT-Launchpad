<?php

use App\Enums\UserRole;
use App\Filament\Pages\MenuMapPage;
use App\Models\User;
use App\Support\MenuMap;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('enumerates the FULL inventory — old menu, both flag-gated groups, and hidden routes', function () {
    // Flags off in the test env — the map must still surface the gated groups (forced on inside).
    config()->set('launchpad.new_setup_enabled', false);
    config()->set('launchpad.new_operate_enabled', false);

    $map = app(MenuMap::class)->build();
    $groups = collect($map['groups'])->keyBy('group');

    // The two parallel-build groups appear with their flag requirement recorded.
    expect($groups)->toHaveKeys(['Setup', 'Operate', 'Settings', 'Advanced', 'Top level']);

    $setupLabels = collect($groups['Setup']['items'])->pluck('label');
    expect($setupLabels)->toContain('Business', 'Interview', 'Locations', 'Services', 'Voice')
        ->and(collect($groups['Setup']['items'])->pluck('flag')->unique()->all())->toBe(['NEW_SETUP']);

    $operateLabels = collect($groups['Operate']['items'])->pluck('label');
    expect($operateLabels)->toContain('Dashboard', 'Blog', 'Core pages', 'Service pages', 'Location pages', 'Physical locations');

    // Hidden-but-routable surfaces are inventoried too, marked hidden.
    $all = collect($map['groups'])->flatMap(fn ($g) => $g['items']);
    $hidden = $all->where('hidden', true)->pluck('label');
    expect($hidden)->toContain('Pages')            // superseded PageResource
        ->and($map['counts']['hidden'])->toBeGreaterThan(0)
        ->and($map['counts']['total'])->toBe($all->count());

    // Duplicate labels are called out for the ordering/naming decision.
    expect(implode(' ', $map['duplicates']))->toContain('Locations');

    // Enumeration never leaks the forced flags back into the app.
    expect((bool) config('launchpad.new_setup_enabled'))->toBeFalse()
        ->and((bool) config('launchpad.new_operate_enabled'))->toBeFalse();
});

it('the Menu map page renders the breakdown with URLs and flag chips', function () {
    Livewire::test(MenuMapPage::class)
        ->assertOk()
        ->assertSee('surfaces total')
        ->assertSee('Operate')
        ->assertSee('Setup')
        ->assertSee('Physical locations')
        ->assertSee('new operate')
        ->assertSee('hidden');
});
