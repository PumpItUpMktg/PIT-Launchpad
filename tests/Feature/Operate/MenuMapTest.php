<?php

use App\Enums\UserRole;
use App\Filament\Pages\Live\LiveLocations;
use App\Filament\Pages\MenuMapPage;
use App\Filament\Resources\BlogTargetResource;
use App\Filament\Resources\CandidateResource;
use App\Filament\Resources\ContentReviewResource;
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
    expect($setupLabels)->toContain('Business', 'Interview', 'Locations', 'Services', 'Voice', 'Silos & keywords', 'Launch')
        ->and(collect($groups['Setup']['items'])->pluck('flag')->unique()->all())->toBe(['NEW_SETUP']);

    $operateLabels = collect($groups['Operate']['items'])->pluck('label');
    expect($operateLabels)->toContain('Dashboard', 'Blog', 'Core pages', 'Service pages', 'Location pages', 'Locations');

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
        ->assertSee('Location pages')
        ->assertSee('new operate')
        ->assertSee('hidden');
});

it('the old Live boards read as "Live Pages" and carry the operate family tag', function () {
    $map = app(MenuMap::class)->build();
    $live = collect($map['groups'])->firstWhere('group', 'Live Pages');

    expect($live)->not->toBeNull()
        ->and(collect($live['items'])->pluck('label')->all())->toBe(['Location pages', 'Service pages', 'Core pages'])
        ->and(collect($live['items'])->pluck('tag')->unique()->all())->toBe(['operate']);
});

it('duplicated legacy links hide once Operate is on; unaddressed items are tagged for the cutover decision', function () {
    // Enumeration forces the flags on — the Local Blog trio (duplicated by Operate → Blog) and
    // the Live Pages trio (duplicated by the Operate pages boards) read hidden + operate-tagged.
    $map = app(MenuMap::class)->build();
    $all = collect($map['groups'])->flatMap(fn ($g) => $g['items']);

    $localBlog = collect($map['groups'])->firstWhere('group', 'Local Blog');
    expect(collect($localBlog['items'])->pluck('hidden')->unique()->all())->toBe([true])
        ->and(collect($localBlog['items'])->pluck('tag')->unique()->all())->toBe(['operate']);

    $livePages = collect($map['groups'])->firstWhere('group', 'Live Pages');
    expect(collect($livePages['items'])->pluck('hidden')->unique()->all())->toBe([true]);

    // Not-yet-placed surfaces carry the unaddressed tag (edit signal, legacy onboarding).
    $unaddressed = $all->where('tag', 'unaddressed')->pluck('label');
    expect($unaddressed)->toContain('Edit signal', 'Onboarding')
        ->and($unaddressed)->not->toContain('Prune', 'Silos & keywords');

    // The old Owner Interview is now SUPERSEDED by the gathering interview + the Business trade
    // field — setup-tagged and hidden once the new Setup menu is on. Same for the structure
    // surfaces: Silos & keywords is Setup step 7 (the generate phase) and Prune is a mode
    // inside it, so both legacy items are setup-tagged and hidden.
    foreach (['Owner Interview', 'Silos & keywords', 'Prune'] as $superseded) {
        $item = $all->firstWhere(fn ($i) => $i['label'] === $superseded && $i['hidden'] === true);
        expect($item)->not->toBeNull()
            ->and($item['tag'])->toBe('setup');
    }

    // FLAG OFF ⇒ the old menu is intact: the trio still registers (the parallel-build promise).
    config()->set('launchpad.new_operate_enabled', false);
    expect(ContentReviewResource::shouldRegisterNavigation())->toBeTrue()
        ->and(LiveLocations::shouldRegisterNavigation())->toBeTrue();

    // FLAG ON ⇒ the duplicates leave the sidebar.
    config()->set('launchpad.new_operate_enabled', true);
    expect(ContentReviewResource::shouldRegisterNavigation())->toBeFalse()
        ->and(CandidateResource::shouldRegisterNavigation())->toBeFalse()
        ->and(BlogTargetResource::shouldRegisterNavigation())->toBeFalse()
        ->and(LiveLocations::shouldRegisterNavigation())->toBeFalse();
});
