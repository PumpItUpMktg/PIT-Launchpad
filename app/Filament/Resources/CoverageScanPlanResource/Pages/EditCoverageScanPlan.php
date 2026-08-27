<?php

namespace App\Filament\Resources\CoverageScanPlanResource\Pages;

use App\Filament\Resources\CoverageScanPlanResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCoverageScanPlan extends EditRecord
{
    protected static string $resource = CoverageScanPlanResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
