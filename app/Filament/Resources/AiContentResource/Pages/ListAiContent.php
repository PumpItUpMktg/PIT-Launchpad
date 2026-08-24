<?php

namespace App\Filament\Resources\AiContentResource\Pages;

use App\Filament\Resources\AiContentResource;
use App\Filament\Widgets\GeoContentSummaryWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;

class ListAiContent extends ListRecords
{
    protected static string $resource = AiContentResource::class;

    /**
     * @return array<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            GeoContentSummaryWidget::class,
        ];
    }
}
