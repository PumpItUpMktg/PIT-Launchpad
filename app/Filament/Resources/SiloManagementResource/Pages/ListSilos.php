<?php

namespace App\Filament\Resources\SiloManagementResource\Pages;

use App\Filament\Resources\SiloManagementResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSilos extends ListRecords
{
    protected static string $resource = SiloManagementResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New silo')];
    }
}
