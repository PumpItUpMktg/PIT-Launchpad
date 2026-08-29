<?php

namespace App\Filament\Pages\Citations;

use App\Citations\Ui\CitationPortfolio;
use App\Citations\Ui\PortfolioRow;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Citations · Portfolio (§ Citations UI, PR D) — the cross-tenant landing at /admin/citations. One row per
 * tenant, exceptions only: median coverage across its listings plus the counts that drive an operator to act
 * (wrong NAP, in flight, stalled), most-urgent-first. Clicking a row drops into that tenant's board.
 * Operator-only via the panel gate.
 *
 * @property-read list<PortfolioRow> $rows
 */
class CitationsPortfolio extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Citations';

    protected static ?string $slug = 'citations';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.citations.portfolio';

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    /** @return list<PortfolioRow> */
    public function getRowsProperty(): array
    {
        return app(CitationPortfolio::class)->rows();
    }
}
