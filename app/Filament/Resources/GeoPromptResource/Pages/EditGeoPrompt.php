<?php

namespace App\Filament\Resources\GeoPromptResource\Pages;

use App\Filament\Resources\GeoPromptResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGeoPrompt extends EditRecord
{
    protected static string $resource = GeoPromptResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
