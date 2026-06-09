<?php

namespace App\Filament\Client\Resources\NewsSourceResource\Pages;

use App\Filament\Client\Resources\NewsSourceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsSources extends ListRecords
{
    protected static string $resource = NewsSourceResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add news source')];
    }
}
