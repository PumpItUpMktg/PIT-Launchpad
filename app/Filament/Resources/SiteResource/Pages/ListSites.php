<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New site')];
    }
}
