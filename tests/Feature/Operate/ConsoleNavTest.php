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

it('marks exactly the six gap items "soon" and gives them no URL', function () {
    $soon = collect(app(ConsoleNav::class)->columns())
        ->flatMap(fn (array $c) => $c['items'])
        ->filter(fn (array $i): bool => $i['soon'])
        ->pluck('label');

    expect($soon->all())->toBe(['Jobs', 'Markets', 'Rankings', 'Indexing', 'Brand', 'Users']);

    // Every soon item is non-clickable (null url); every live item resolves to a real /admin URL.
    foreach (app(ConsoleNav::class)->columns() as $col) {
        foreach ($col['items'] as $item) {
            if ($item['soon']) {
                expect($item['url'])->toBeNull();
            } else {
                expect($item['url'])->toBeString()->toContain('/admin/');
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
        ->toContain('>Markets<') // soon item present as plain text, not a link
        ->toContain('lp-cn-soon')
        ->toContain('soon');

    // The six soon items render as non-clickable spans (no href), the 18 live ones as links.
    expect(substr_count($html, 'class="lp-cn-soon"'))->toBe(6)
        ->and(substr_count($html, 'wire:navigate'))->toBe(18); // exactly the 18 live links
});
