<?php

namespace App\Filament\Pages\Citations;

use App\Citations\Ui\CitationPortfolio;
use App\Citations\Ui\PortfolioRow;
use App\Models\Directory;
use BackedEnum;
use Database\Seeders\DirectorySeeder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedDirectories')
                ->label('Seed directory catalog')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Seed the directory catalog')
                ->modalDescription('Loads the top national citation directories (Google, Yelp, Facebook, BBB, Angi, and more) into the shared catalog. Safe to run again — existing entries are updated in place, not duplicated.')
                ->modalSubmitActionLabel('Seed catalog')
                ->action(function (): void {
                    app(DirectorySeeder::class)->run();

                    Notification::make()->success()
                        ->title('Directory catalog ready')
                        ->body(Directory::query()->count().' directories in the catalog.')
                        ->send();
                }),
        ];
    }

    /** @return list<PortfolioRow> */
    public function getRowsProperty(): array
    {
        return app(CitationPortfolio::class)->rows();
    }
}
