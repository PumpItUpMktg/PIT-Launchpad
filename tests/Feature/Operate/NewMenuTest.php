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

    // The FINAL IA (nav-final): Top level (Dashboard · Portfolio · Setup) → Operate (the pages boards).
    // The nine Setup steps and the Advanced build-time tools are off-menu now (they inventory as
    // drilldowns), so the sidebar is just the two keeper groups.
    expect(collect($m['menu'])->pluck('group')->all())->toBe(['Top level', 'Operate']);

    // Top level: the three entries, in cutover order (Dashboard, Portfolio, Setup).
    expect(collect($groups['Top level']['items'])->pluck('label')->all())
        ->toBe(['Dashboard', 'Portfolio', 'Setup']);

    // Operate: the pages boards + the internal-link audit surface (Portfolio + Dashboard are Top level).
    expect(collect($groups['Operate']['items'])->pluck('label')->all())
        ->toBe(['Blog', 'Core pages', 'Service pages', 'Location pages', 'Locations', 'Internal Links']);

    // No legacy label — nor any off-menu step/tool — leaks into the final sidebar.
    $menuLabels = collect($m['menu'])->flatMap(fn ($g) => collect($g['items'])->pluck('label'));
    expect($menuLabels)->not->toContain('Grow', 'Overview', 'Review queue', 'Candidates', 'Prune', 'Owner Interview', 'Service area', 'Feeds', 'Connections', 'Business', 'Launch', 'Edit signal', 'Menu map');
});

it('splits the rest honestly: pending decisions, retiring legacy, and linked drill-downs', function () {
    $m = app(NewMenu::class)->build();

    // Pending = only the disabled legacy Onboarding wizard now (Edit signal is PLACED in Advanced).
    $pending = collect($m['pending'])->pluck('label');
    expect($pending)->toContain('Onboarding')
        ->and($pending)->not->toContain('Edit signal', 'Brand studio');

    // Retiring = every setup/operate family-tagged legacy surface.
    $retiring = collect($m['retiring'])->pluck('label');
    expect($retiring)->toContain('Grow', 'Silos & keywords', 'Prune', 'Owner Interview', 'Review queue', 'Candidates')
        ->and($retiring)->not->toContain('Launch', 'Dashboard');

    // Drill-downs = hidden routes reached from inside surfaces.
    $drill = collect($m['drilldowns'])->pluck('label');
    expect($drill)->toContain('Proof Editor', 'Site Cockpit', 'Targets & gaps', 'Silos');

    // Every enumerated surface lands in exactly one bucket.
    $total = $m['counts']['menu'] + $m['counts']['pending'] + $m['counts']['retiring'] + $m['counts']['drilldowns'];
    expect($total)->toBe(app(MenuMap::class)->build()['counts']['total']);
});

it('the New menu page renders the worksheet with every routable row clickable', function () {
    Livewire::test(NewMenuPage::class)
        ->assertOk()
        ->assertSee('in the final menu')
        ->assertSee('Silos & keywords')
        ->assertSee('Launch')
        ->assertSee('Brand')
        ->assertSee('Retiring at cutover')
        // Every bucket links its live routes so each page can be opened and evaluated:
        ->assertSeeHtml('href="'.LaunchStep::getUrl().'"')   // off-menu step (drilldown), route kept
        ->assertSeeHtml('href="'.Grow::getUrl().'"')            // retiring
        ->assertSeeHtml('href="'.ProofEditor::getUrl().'"');           // drill-down
});
