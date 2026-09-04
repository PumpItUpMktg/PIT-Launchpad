<?php

namespace App\Filament\Pages\Operate;

use Filament\Actions\Action;

/**
 * Operate · Service pages — hubs + spokes (service/pillar/cluster), full lifecycle. Legacy per-family
 * board, kept as a route (off-nav); the consolidated {@see OperatePages} board is the nav surface.
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

    /** Service pages carry the on-demand ranking pull (the action itself lives on the base). */
    protected function getHeaderActions(): array
    {
        return [$this->refreshRankingsAction(), $this->submitSitemapAction(), $this->pingIndexNowAction()];
    }
}
