<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use App\Filament\Resources\LocationResource\Concerns\SyncsNapProfile;
use App\Support\BusinessHours;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    use SyncsNapProfile;

    protected static string $resource = LocationResource::class;

    /** After a GBP-backed location is created, auto-fill its canonical NAP profile from the Google data. */
    protected function afterCreate(): void
    {
        $this->syncNapFromGbp();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [LocationResource::importAction()];
    }

    /**
     * Fold the flat per-day fields back into the stored day-keyed `hours` map at
     * the persistence boundary, then drop the scratch fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['hours'] = BusinessHours::fromFields($data);

        foreach (BusinessHours::DAYS as $day) {
            unset($data["hours_{$day}_state"], $data["hours_{$day}_open"], $data["hours_{$day}_close"]);
        }

        return $data;
    }
}
