<?php

namespace App\Filament\Pages\Operate;

/**
 * Operate · Core pages — home + standard pages, full lifecycle (work lane + live cards).
 */
class OperateCorePages extends OperatePagesBoard
{
    protected static ?string $slug = 'operate/pages/core';

    protected static ?string $navigationLabel = 'Core pages';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.operate.pages-board';

    protected function family(): string
    {
        return 'core';
    }
}
