<?php

namespace App\Filament\Resources\ReviewCaptureResource\Pages;

use App\Enums\ReviewStatus;
use App\Filament\Pages\ReviewImportPage;
use App\Filament\Resources\ReviewCaptureResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The consolidated Reviews surface (Relay 3 · PR 5d): one board, the review lifecycle across tabs —
 * Awaiting approval · Needs market · Published — with the bulk Import flow folded in as a header
 * action (it is a full upload → map → preview → commit page, {@see ReviewImportPage}). "Needs market"
 * is the operator wording for a review with no location assigned (`needs_location`), the same signal
 * the Lobby surfaces as "reviews with no market".
 */
class ListReviews extends ListRecords
{
    protected static string $resource = ReviewCaptureResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'awaiting' => Tab::make('Awaiting approval')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ReviewStatus::Pending->value)),
            'needs_market' => Tab::make('Needs market')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('needs_location', true)),
            'published' => Tab::make('Published')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ReviewStatus::Published->value)),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label('Import reviews')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(ReviewImportPage::getUrl()),
        ];
    }
}
