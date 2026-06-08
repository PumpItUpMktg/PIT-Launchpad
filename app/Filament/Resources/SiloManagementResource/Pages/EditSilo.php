<?php

namespace App\Filament\Resources\SiloManagementResource\Pages;

use App\Filament\Resources\SiloManagementResource;
use App\Models\Silo;
use App\Operator\Coverage\SiloManager;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSilo extends EditRecord
{
    protected static string $resource = SiloManagementResource::class;

    /**
     * Surface §4's viability guard when opening a silo for editing.
     */
    protected function afterFill(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Silo) {
            $warning = app(SiloManager::class)->viabilityWarning($record);

            if ($warning !== null) {
                Notification::make()->warning()->title('Viability')->body($warning)->send();
            }
        }
    }
}
