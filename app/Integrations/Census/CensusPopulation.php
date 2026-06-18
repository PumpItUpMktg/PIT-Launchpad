<?php

namespace App\Integrations\Census;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Census ACS5 population for county subdivisions, used to group covered towns by size
 * (Large / Medium / Small). One call per county returns total population (B01003_001E)
 * per 10-digit county-subdivision GEOID — which matches the TIGERweb subdivision GEOID,
 * so the join is a direct key lookup. Results are cached (population is stable).
 *
 * The ACS API REQUIRES a key (a keyless request redirects to a "Missing Key" page), so
 * without CENSUS_API_KEY this degrades to an empty map — towns simply come back ungrouped
 * rather than erroring.
 */
class CensusPopulation
{
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $apiKey,
        private readonly string $year,
        private readonly string $baseUrl = 'https://api.census.gov/data',
        private readonly int $cacheDays = 30,
        private readonly int $timeout = 20,
    ) {}

    /**
     * @return array<string, int> 10-digit county-subdivision GEOID => total population
     */
    public function forCounty(string $stateFips, string $countyFips): array
    {
        if ($this->apiKey === '') {
            return []; // no key → degrade (towns ungrouped), never error
        }

        return $this->cache->remember(
            "acs:pop:{$this->year}:{$stateFips}:{$countyFips}",
            now()->addDays($this->cacheDays),
            fn () => $this->fetch($stateFips, $countyFips),
        );
    }

    /**
     * @return array<string, int>
     */
    private function fetch(string $stateFips, string $countyFips): array
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get(
                rtrim($this->baseUrl, '/')."/{$this->year}/acs/acs5",
                [
                    'get' => 'NAME,B01003_001E',
                    'for' => 'county subdivision:*',
                    'in' => "state:{$stateFips} county:{$countyFips}",
                    'key' => $this->apiKey,
                ],
            );
        } catch (Throwable) {
            return [];
        }

        $rows = $response->json();
        if (! is_array($rows) || count($rows) < 2 || ! is_array($rows[0])) {
            return []; // missing-key HTML or empty → no population
        }

        $idx = array_flip($rows[0]); // header: NAME, B01003_001E, state, county, county subdivision
        $pop = $idx['B01003_001E'] ?? 1;
        $st = $idx['state'] ?? 2;
        $co = $idx['county'] ?? 3;
        $sub = $idx['county subdivision'] ?? 4;

        $out = [];
        foreach (array_slice($rows, 1) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $geoId = (string) ($row[$st] ?? '').(string) ($row[$co] ?? '').(string) ($row[$sub] ?? '');
            if (strlen($geoId) === 10) {
                $out[$geoId] = (int) ($row[$pop] ?? 0);
            }
        }

        return $out;
    }
}
