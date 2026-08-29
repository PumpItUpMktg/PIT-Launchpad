<?php

namespace App\Filament\Resources\DirectoryResource\Pages;

use App\Filament\Resources\DirectoryResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDirectory extends EditRecord
{
    protected static string $resource = DirectoryResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
