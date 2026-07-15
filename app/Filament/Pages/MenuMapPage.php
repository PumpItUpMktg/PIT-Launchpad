<?php

namespace App\Filament\Pages;

use App\Support\MenuMap;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Menu map — the ONE view of every admin surface (old menu + both flag-gated groups, hidden
 * routes included), enumerated programmatically so the inventory can't miss anything. The
 * planning surface for the cutover: see the full breakdown, spot duplicates, decide the final
 * order. Internal tool — lives in Advanced.
 *
 * @property-read array{groups: list<array{group: string, items: list<array<string, mixed>>}>, duplicates: list<string>, counts: array<string, int>} $map
 */
class MenuMapPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Menu map';

    protected static string|\UnitEnum|null $navigationGroup = 'Advanced';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'menu-map';

    protected string $view = 'filament.pages.menu-map';

    /**
     * @return array{groups: list<array{group: string, items: list<array<string, mixed>>}>, duplicates: list<string>, counts: array<string, int>}
     */
    public function getMapProperty(): array
    {
        return app(MenuMap::class)->build();
    }
}
