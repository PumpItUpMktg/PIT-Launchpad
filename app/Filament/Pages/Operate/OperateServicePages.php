<?php

namespace App\Filament\Pages\Operate;

use App\Jobs\RefreshSitePositions;
use App\KeywordGenerator\Pipeline\PositionPullEstimator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Operate · Service pages — hubs + spokes (service/pillar/cluster), full lifecycle.
 */
class OperateServicePages extends OperatePagesBoard
{
    protected static ?string $slug = 'operate/pages/services';

    protected static ?string $navigationLabel = 'Service pages';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.operate.pages-board';

    protected function family(): string
    {
        return 'services';
    }

    /**
     * "Refresh rankings now" — the operator's one-time, on-demand DataForSEO ranking pull for the
     * current site (positions only; it does NOT discover new keywords). Guarded by a confirmation that
     * spells out the DataForSEO credit spend with an up-front estimate, because it costs real credits.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshRankings')
                ->label('Refresh rankings now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Pull fresh rankings from DataForSEO')
                ->modalDescription(fn (): string => $this->pullDisclaimer())
                ->modalSubmitActionLabel('Yes, pull now (uses credits)')
                ->action(fn () => $this->refreshRankings()),
        ];
    }

    private function refreshRankings(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            Notification::make()->warning()->title('No site selected')->send();

            return;
        }

        $estimate = app(PositionPullEstimator::class)->estimate($site);
        if ($estimate->isEmpty()) {
            Notification::make()->warning()
                ->title('Nothing to pull yet')
                ->body('This site has no tracked keywords to refresh — run keyword discovery first (Setup → Silos & keywords), then pull rankings.')
                ->send();

            return;
        }

        RefreshSitePositions::dispatch($site->id);

        Notification::make()->success()
            ->title('Pulling rankings — using DataForSEO credits')
            ->body(sprintf(
                'Refreshing %d tracked keyword(s) (~%d task(s), est. %s). Rankings update on the cards within ~5–15 minutes.',
                $estimate->keywords,
                $estimate->totalTasks(),
                $estimate->costLabel(),
            ))
            ->send();
    }

    /** The confirmation-modal disclaimer: what the pull does, how many DataForSEO tasks, and the cost estimate. */
    private function pullDisclaimer(): string
    {
        $site = $this->getSite();
        if ($site === null) {
            return 'Select a site first.';
        }

        $e = app(PositionPullEstimator::class)->estimate($site);
        if ($e->isEmpty()) {
            return 'This site has no tracked keywords yet, so there is nothing to pull. Run keyword discovery first (Setup → Silos & keywords).';
        }

        $lines = [];
        if ($e->organicTasks > 0) {
            $lines[] = sprintf('%d organic SERP task(s)', $e->organicTasks);
        }
        if ($e->localTasks > 0) {
            $lines[] = sprintf('%d local-grid task(s) (%d-point grid × priority market)', $e->localTasks, $e->gridPoints);
        }

        return sprintf(
            'This runs a live ranking pull for %d tracked keyword(s): %s — about %d DataForSEO task(s), estimated %s. It spends DataForSEO credits (the estimate is indicative, not a bill). Positions only — no new keywords are discovered. In standard mode results appear on the cards within ~5–15 minutes.',
            $e->keywords,
            implode(' + ', $lines),
            $e->totalTasks(),
            $e->costLabel(),
        );
    }
}
