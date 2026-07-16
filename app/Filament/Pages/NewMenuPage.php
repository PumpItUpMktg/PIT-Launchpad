<?php

namespace App\Filament\Pages;

use App\Support\NewMenu;
use BackedEnum;
use Filament\Pages\Page;

/**
 * New menu — the TEMP cutover worksheet: only the newly designed surfaces, in proposed final
 * order, with the pending decisions and the retiring legacy list alongside. The studio
 * rebuild (site-wide CSS/design pass) works from this page; it retires with the cutover.
 * Sibling to {@see MenuMapPage} (the full inventory) — this is the reduced target state.
 *
 * @property-read array{menu: list<array{group: string, items: list<array<string, mixed>>}>, pending: list<array<string, mixed>>, retiring: list<array<string, mixed>>, drilldowns: list<array<string, mixed>>, counts: array<string, int>} $menu
 */
class NewMenuPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'New menu';

    protected static string|\UnitEnum|null $navigationGroup = 'Advanced';

    protected static ?int $navigationSort = 98;

    protected static ?string $slug = 'new-menu';

    protected string $view = 'filament.pages.new-menu';

    /**
     * @return array{menu: list<array{group: string, items: list<array<string, mixed>>}>, pending: list<array<string, mixed>>, retiring: list<array<string, mixed>>, drilldowns: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function getMenuProperty(): array
    {
        return app(NewMenu::class)->build();
    }
}
