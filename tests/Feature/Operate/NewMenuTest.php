<?php

use App\Enums\UserRole;
use App\Filament\Pages\Gathering\LaunchStep;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\NewMenuPage;
use App\Filament\Pages\ProofEditor;
use App\Models\User;
use App\Support\MenuMap;
use App\Support\NewMenu;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The temp "New menu" worksheet — the Menu map reduced to the proposed final menu (only the
 * newly designed surfaces) plus the pending / retiring / drill-down buckets. Retires at cutover.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('the final menu carries ONLY the newly designed surfaces, in cutover order', function () {
    $m = app(NewMenu::class)->build();
    $groups = collect($m['menu'])->keyBy('group');

    // Group order: build first (Setup), then run (Operate); internal tools last.
    expect(collect($m['menu'])->pluck('group')->all())->toBe(['Top level', 'Setup', 'Operate', 'Advanced']);

    // Top level keeps only the two portfolio-wide entries.
    expect(collect($groups['Top level']['items'])->pluck('label')->all())->toBe(['Overview', 'Portfolio']);

    // Setup: the full 1–8 arc, in step order.
    expect(collect($groups['Setup']['items'])->pluck('label')->all())
        ->toBe(['Business', 'Interview', 'Locations', 'Services', 'Voice', 'Connections & Feeds', 'Silos & keywords', 'Launch']);

    // Operate: the six boards.
    expect(collect($groups['Operate']['items'])->pluck('label')->all())
        ->toBe(['Dashboard', 'Blog', 'Core pages', 'Service pages', 'Location pages', 'Physical locations']);

    // No legacy label leaks into the final menu.
    $menuLabels = collect($m['menu'])->flatMap(fn ($g) => collect($g['items'])->pluck('label'));
    expect($menuLabels)->not->toContain('Grow', 'Setup', 'Review queue', 'Candidates', 'Prune', 'Owner Interview');
});

it('splits the rest honestly: pending decisions, retiring legacy, and linked drill-downs', function () {
    $m = app(NewMenu::class)->build();

    // Pending = the unaddressed items + the one known native-rebuild hole (Brand studio).
    $pending = collect($m['pending'])->pluck('label');
    expect($pending)->toContain('Edit signal', 'Onboarding', 'Brand studio');

    // Retiring = every setup/operate family-tagged legacy surface.
    $retiring = collect($m['retiring'])->pluck('label');
    expect($retiring)->toContain('Grow', 'Silos & keywords', 'Prune', 'Owner Interview', 'Review queue', 'Candidates')
        ->and($retiring)->not->toContain('Launch', 'Dashboard');

    // Drill-downs = hidden routes reached from inside surfaces.
    $drill = collect($m['drilldowns'])->pluck('label');
    expect($drill)->toContain('Proof Editor', 'Site Cockpit', 'Targets & gaps', 'Silos');

    // Every enumerated surface lands in exactly one bucket.
    $total = $m['counts']['menu'] + $m['counts']['pending'] - 1 // minus the synthetic Brand studio row
        + $m['counts']['retiring'] + $m['counts']['drilldowns'];
    expect($total)->toBe(app(MenuMap::class)->build()['counts']['total']);
});

it('the New menu page renders the worksheet with every routable row clickable', function () {
    Livewire::test(NewMenuPage::class)
        ->assertOk()
        ->assertSee('in the final menu')
        ->assertSee('Silos & keywords')
        ->assertSee('Launch')
        ->assertSee('Brand studio')
        ->assertSee('Retiring at cutover')
        // Every bucket links its live routes so each page can be opened and evaluated:
        ->assertSeeHtml('href="'.LaunchStep::getUrl().'"')   // final menu
        ->assertSeeHtml('href="'.Grow::getUrl().'"')            // retiring
        ->assertSeeHtml('href="'.ProofEditor::getUrl().'"');           // drill-down
});
