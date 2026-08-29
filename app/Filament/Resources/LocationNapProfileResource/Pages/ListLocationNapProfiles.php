<?php

namespace App\Filament\Resources\LocationNapProfileResource\Pages;

use App\Filament\Resources\LocationNapProfileResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationNapProfiles extends ListRecords
{
    protected static string $resource = LocationNapProfileResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
