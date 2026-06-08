<?php

namespace App\Filament\Resources\SourceResource\Pages;

use App\Filament\Resources\SourceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSources extends ListRecords
{
    protected static string $resource = SourceResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
