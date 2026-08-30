<?php

namespace App\Filament\Resources\LocationNapProfileResource\Pages;

use App\Citations\NapProfileHydrator;
use App\Filament\Resources\LocationNapProfileResource;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLocationNapProfile extends CreateRecord
{
    protected static string $resource = LocationNapProfileResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('autofillFromGbp')
                ->label('Autofill from GBP')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->action(function (): void {
                    $locationId = data_get($this->data, 'location_id');
                    if (! is_string($locationId) || $locationId === '') {
                        Notification::make()->warning()->title('Pick a location first')
                            ->body('Choose the location above, then autofill from its Google Business Profile.')->send();

                        return;
                    }

                    $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($locationId);
                    if ($location === null) {
                        return;
                    }

                    $derived = app(NapProfileHydrator::class)->deriveFromLocation($location);
                    if ($derived === []) {
                        Notification::make()->warning()->title('No GBP data on that location')
                            ->body('Import a Google Business Profile onto the location first, then autofill here.')->send();

                        return;
                    }

                    // Explicit autofill: the GBP is canonical, so its values win. The operator reviews before
                    // saving (never a silent save) and can still tweak any field afterward.
                    $current = is_array($this->data) ? $this->data : [];

                    $this->form->fill(array_merge($current, $derived));

                    Notification::make()->success()->title('Filled from Google — review and save')->send();
                }),
        ];
    }
}
