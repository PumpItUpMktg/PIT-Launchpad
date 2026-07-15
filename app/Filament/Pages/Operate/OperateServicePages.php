<?php

namespace App\Filament\Pages\Operate;

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
}
