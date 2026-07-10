<?php

namespace App\Local\Grounding;

use App\Models\Location;
use Illuminate\Support\Facades\Http;

/**
 * Census ACS facts for the location's home county — population scale and median housing age
 * ("homes here mostly date from the 1950s" is a load-bearing fact for basement/plumbing trades).
 * Free, no key (small request volumes). County-level (the home_county_geoid captured at territory
 * intake); per-town ACS lands with the town-page work.
 */
final class CensusAcsProvider implements GroundingProvider
{
    private const URL = 'https://api.census.gov/data/2023/acs/acs5';

    public function fetch(Location $location): array
    {
        $geoid = trim((string) $location->home_county_geoid);
        if (strlen($geoid) !== 5) {
            return ['facts' => [], 'source' => 'census acs5'];
        }

        $response = Http::timeout(10)->get(self::URL, [
            'get' => 'NAME,B01003_001E,B25003_001E,B25035_001E', // population, households, median year built
            'for' => 'county:'.substr($geoid, 2),
            'in' => 'state:'.substr($geoid, 0, 2),
        ]);
        $rows = $response->successful() ? $response->json() : null;
        if (! is_array($rows) || count($rows) < 2 || ! is_array($rows[1])) {
            return ['facts' => [], 'source' => 'census acs5'];
        }

        [$name, $population, $households, $yearBuilt] = array_pad($rows[1], 4, null);

        $facts = [];
        if (is_numeric($population)) {
            $facts[] = sprintf('%s is home to about %s people (%s households).',
                (string) $name, number_format((int) $population), is_numeric($households) ? number_format((int) $households) : '—');
        }
        if (is_numeric($yearBuilt) && (int) $yearBuilt > 1900) {
            $facts[] = sprintf('The median home in %s was built around %d.', (string) $name, (int) $yearBuilt);
        }

        return ['facts' => $facts, 'source' => 'census acs5 (2023)'];
    }
}
