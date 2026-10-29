<?php

namespace App\Filament\Resources\LeadFormResource\Pages;

use App\Filament\Resources\LeadFormResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLeadForm extends EditRecord
{
    protected static string $resource = LeadFormResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
