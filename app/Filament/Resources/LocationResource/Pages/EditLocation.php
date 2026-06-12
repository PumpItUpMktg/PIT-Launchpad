<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use App\Support\BusinessHours;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Expand the stored day-keyed `hours` map into the flat per-day form fields on
     * load (legacy numeric-keyed rows are repaired by normalize() inside toFields).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = array_merge($data, BusinessHours::toFields(is_array($data['hours'] ?? null) ? $data['hours'] : null));
        unset($data['hours']);

        return $data;
    }

    /**
     * Fold the flat per-day fields back into the stored day-keyed map for storage,
     * then drop the scratch fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['hours'] = BusinessHours::fromFields($data);

        foreach (BusinessHours::DAYS as $day) {
            unset($data["hours_{$day}_state"], $data["hours_{$day}_open"], $data["hours_{$day}_close"]);
        }

        return $data;
    }
}
