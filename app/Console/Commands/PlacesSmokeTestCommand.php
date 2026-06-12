<?php

namespace App\Console\Commands;

use App\Integrations\Places\PlacesProvider;
use Illuminate\Console\Command;

/**
 * Confirm the Google Places API is enabled + reachable for the location import
 * (the brief's "first task" — fail with a clear operator-facing reason if not).
 * Run after wiring GOOGLE_MAPS_API_KEY / enabling the Places API on the project.
 */
class PlacesSmokeTestCommand extends Command
{
    protected $signature = 'launchpad:places-smoke-test';

    protected $description = 'Verify the Google Places API is enabled/reachable for location import.';

    public function handle(PlacesProvider $places): int
    {
        $status = $places->smokeTest();

        if ($status->ok) {
            $this->info('✓ Places API reachable — '.$status->message);

            return self::SUCCESS;
        }

        $this->error('✗ Places API unavailable — '.$status->message);

        return self::FAILURE;
    }
}
