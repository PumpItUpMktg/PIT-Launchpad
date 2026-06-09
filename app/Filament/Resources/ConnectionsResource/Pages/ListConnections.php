<?php

namespace App\Filament\Resources\ConnectionsResource\Pages;

use App\Filament\Resources\ConnectionsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListConnections extends ListRecords
{
    protected static string $resource = ConnectionsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [ConnectionsResource::connectWordPressAction()];
    }
}
