<?php

namespace App\Filament\Resources\LocationResource\Concerns;

use App\Citations\NapProfileHydrator;
use App\Models\Location;
use Filament\Notifications\Notification;

/**
 * Shared by CreateLocation + EditLocation: after a GBP-backed location is saved, derive its canonical NAP
 * profile from the imported Google data (non-destructive — see {@see NapProfileHydrator}) and tell the operator
 * what happened. Non-GBP locations are left alone so this never manufactures a bogus NAP.
 */
trait SyncsNapProfile
{
    protected function syncNapFromGbp(): void
    {
        $location = $this->record ?? null;
        if (! $location instanceof Location) {
            return;
        }

        // Only GBP-backed locations carry the structured data a NAP needs.
        if (blank($location->place_id) && blank($location->gbp_url)) {
            return;
        }

        $result = app(NapProfileHydrator::class)->hydrate($location);

        if ($result->created()) {
            Notification::make()->success()
                ->title('NAP profile auto-filled from Google')
                ->body('The location now has a canonical NAP ready to scan — review it under NAP profiles.')
                ->send();

            return;
        }

        if ($result->updated()) {
            Notification::make()->success()
                ->title('NAP profile updated from Google')
                ->body('Filled blank fields: '.implode(', ', $result->fields).'. Existing values were left untouched.')
                ->send();

            return;
        }

        if ($result->skipped()) {
            Notification::make()->warning()
                ->title('NAP not auto-filled')
                ->body("Google didn't supply: ".implode(', ', $result->missing).'. Add these on the NAP profile.')
                ->send();
        }
    }
}
