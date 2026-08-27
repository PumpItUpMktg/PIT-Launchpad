<?php

namespace App\Filament\Resources\CoverageScanPlanResource\Pages;

use App\Filament\Resources\CoverageScanPlanResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCoverageScanPlans extends ListRecords
{
    protected static string $resource = CoverageScanPlanResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New plan')];
    }
}
