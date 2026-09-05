<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Operator\Nav\ConsoleNav;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

it('is a four-column header of exactly 24 items in the settled group order', function () {
    $structure = app(ConsoleNav::class)->structure();

    expect(collect($structure)->pluck('group')->all())->toBe(['Build', 'Territory', 'Results', 'System']);

    $counts = collect($structure)->mapWithKeys(fn (array $c): array => [$c['group'] => count($c['items'])]);
    expect($counts->all())->toBe(['Build' => 7, 'Territory' => 6, 'Results' => 5, 'System' => 6])
        ->and($counts->sum())->toBe(24);
});

it('places every item in its settled group and vocabulary', function () {
    $byGroup = collect(app(ConsoleNav::class)->structure())
        ->mapWithKeys(fn (array $c): array => [$c['group'] => collect($c['items'])->pluck('label')->all()]);

    expect($byGroup['Build'])->toBe(['Dashboard', 'Setup', 'Posts', 'Pages', 'Jobs', 'Reviews', 'Live'])
        ->and($byGroup['Territory'])->toBe(['Markets', 'Towns', 'Citations', 'Silos', 'Keywords', 'Internal links'])
        ->and($byGroup['Results'])->toBe(['Rankings', 'Indexing', 'Geo grid', 'Coverage', 'AI visibility'])
        ->and($byGroup['System'])->toBe(['Connections', 'Feeds', 'Brand', 'Voice', 'Users', 'Recover']);
});

it('has no remaining gap items — all 24 surfaces are live', function () {
    $soon = collect(app(ConsoleNav::class)->columns())
        ->flatMap(fn (array $c) => $c['items'])
        ->filter(fn (array $i): bool => $i['soon'])
        ->pluck('label');

    // Brand + Users shipped — the final two GAP surfaces of the nav cutover. No "soon" items remain.
    expect($soon->all())->toBe([]);

    // Every one of the 24 items resolves to a real /admin URL (Dashboard lands at '/admin' with no
    // trailing path, so match '/admin', not '/admin/').
    $items = collect(app(ConsoleNav::class)->columns())->flatMap(fn (array $c) => $c['items']);
    expect($items)->toHaveCount(24);
    foreach ($items as $item) {
        expect($item['soon'])->toBeFalse()
            ->and($item['url'])->toBeString()->toContain('/admin');
    }
});

it('renders the four-column header — group titles and 24 live links, no greyed "soon" items', function () {
    $html = View::make('filament.operator.console-nav')->render();

    // The four group columns, each titled.
    expect($html)->toContain('Build')->toContain('Territory')->toContain('Results')->toContain('System')
        ->toContain('Dashboard')
        ->toContain('>Rankings<')
        ->toContain('>Indexing<')
        ->toContain('>Brand<')  // now a live link
        ->toContain('>Users<'); // now a live link

    // All 24 items render as links; no "soon" spans remain.
    expect(substr_count($html, 'class="lp-cn-soon"'))->toBe(0)
        ->and(substr_count($html, 'wire:navigate'))->toBe(24); // the full 24 live links
});
