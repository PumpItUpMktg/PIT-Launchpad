<?php

namespace App\Filament\Resources\LocationNapProfileResource\Pages;

use App\Filament\Resources\LocationNapProfileResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLocationNapProfile extends EditRecord
{
    protected static string $resource = LocationNapProfileResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
