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

it('marks exactly the two remaining gap items "soon" and gives them no URL', function () {
    $soon = collect(app(ConsoleNav::class)->columns())
        ->flatMap(fn (array $c) => $c['items'])
        ->filter(fn (array $i): bool => $i['soon'])
        ->pluck('label');

    // Markets + Jobs + Rankings + Indexing shipped (Relay 3) — live links now, not gaps.
    expect($soon->all())->toBe(['Brand', 'Users']);

    // Every soon item is non-clickable (null url); every live item resolves to a real /admin URL.
    // (Dashboard is now the panel landing at '/admin' — no trailing path — so match '/admin', not '/admin/'.)
    foreach (app(ConsoleNav::class)->columns() as $col) {
        foreach ($col['items'] as $item) {
            if ($item['soon']) {
                expect($item['url'])->toBeNull();
            } else {
                expect($item['url'])->toBeString()->toContain('/admin');
            }
        }
    }
});

it('renders the four-column header — group titles, live links, and greyed "soon" items', function () {
    $html = View::make('filament.operator.console-nav')->render();

    // The four group columns, each titled.
    expect($html)->toContain('Build')->toContain('Territory')->toContain('Results')->toContain('System')
        // A live item is an anchor; a gap item is a greyed non-link with a "soon" tag.
        ->toContain('Dashboard')
        ->toContain('>Rankings<') // Rankings present (a live link)
        ->toContain('>Indexing<') // Indexing present (now a live link)
        ->toContain('>Brand<')    // a remaining soon item, present as plain text
        ->toContain('lp-cn-soon')
        ->toContain('soon');

    // The two soon items render as non-clickable spans (no href), the 22 live ones as links.
    expect(substr_count($html, 'class="lp-cn-soon"'))->toBe(2)
        ->and(substr_count($html, 'wire:navigate'))->toBe(22); // exactly the 22 live links
});
