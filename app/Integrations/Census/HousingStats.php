<?php

namespace App\Integrations\Census;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * ACS5 housing-stock stats per town: median year built, tenure, units in structure, and the pre-1960 share
 * of the stock — the variables that actually change how a home-services page reads. A town whose stock
 * mostly predates 1960 has fieldstone foundations and galvanized pipe; one built in 2005 has neither.
 *
 * One request returns EVERY town in a county (or every place in a state), so a 54-town territory costs one
 * call, cached for a month — these are 5-year estimates, they do not move.
 *
 * The ACS API REQUIRES a key: a keyless request 302s to a "Missing Key" HTML page (verified against the
 * live API, not assumed). Without `CENSUS_API_KEY` this degrades to an empty map — towns simply carry no
 * housing facts rather than the caller erroring.
 *
 * Variable names verified against https://api.census.gov/data/{year}/acs/acs5/variables.json:
 *   B25035_001E median year structure built · B25003_001E/_002E occupied / owner-occupied
 *   B25024_001E/_002E/_003E units in structure: total / 1-unit detached / 1-unit attached
 *   B25034_009E/_010E/_011E built 1950-59 / 1940-49 / 1939 or earlier
 */
class HousingStats
{
    private const VARIABLES = [
        'B25035_001E', 'B25003_001E', 'B25003_002E',
        'B25024_001E', 'B25024_002E', 'B25024_003E',
        'B25034_009E', 'B25034_010E', 'B25034_011E',
    ];

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $apiKey,
        private readonly string $year,
        private readonly string $baseUrl = 'https://api.census.gov/data',
        private readonly int $cacheDays = 30,
        private readonly int $timeout = 30,
    ) {}

    public function year(): string
    {
        return $this->year;
    }

    /**
     * Every county subdivision in one county, keyed by its 10-digit GEOID.
     *
     * @return array<string, array<string, int|string|null>>
     */
    public function forCountySubdivisions(string $stateFips, string $countyFips): array
    {
        return $this->remember("acs:housing:sub:{$this->year}:{$stateFips}:{$countyFips}", fn (): array => $this->fetch(
            ['for' => 'county subdivision:*', 'in' => "state:{$stateFips} county:{$countyFips}"],
            ['state', 'county', 'county subdivision'],
        ));
    }

    /**
     * Every place in one state, keyed by its 7-digit GEOID.
     *
     * @return array<string, array<string, int|string|null>>
     */
    public function forPlaces(string $stateFips): array
    {
        return $this->remember("acs:housing:place:{$this->year}:{$stateFips}", fn (): array => $this->fetch(
            ['for' => 'place:*', 'in' => "state:{$stateFips}"],
            ['state', 'place'],
        ));
    }

    /**
     * @param  callable(): array<string, array<string, int|string|null>>  $fetch
     * @return array<string, array<string, int|string|null>>
     */
    private function remember(string $key, callable $fetch): array
    {
        if ($this->apiKey === '') {
            return [];   // no key → degrade (no housing facts), never error
        }

        return $this->cache->remember($key, now()->addDays($this->cacheDays), $fetch);
    }

    /**
     * @param  array<string, string>  $geography
     * @param  list<string>  $geoIdParts  the header columns whose values concatenate into the GEOID
     * @return array<string, array<string, int|string|null>>
     */
    private function fetch(array $geography, array $geoIdParts): array
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get(
                rtrim($this->baseUrl, '/')."/{$this->year}/acs/acs5",
                $geography + ['get' => 'NAME,'.implode(',', self::VARIABLES), 'key' => $this->apiKey],
            );
        } catch (Throwable) {
            return [];
        }

        $rows = $response->json();
        if (! is_array($rows) || count($rows) < 2 || ! is_array($rows[0])) {
            return [];   // missing-key HTML or an empty answer → no housing facts
        }

        /** @var array<string, int> $idx */
        $idx = array_flip(array_map('strval', $rows[0]));
        $out = [];
        foreach (array_slice($rows, 1) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $geoId = '';
            foreach ($geoIdParts as $part) {
                $geoId .= (string) ($row[$idx[$part] ?? -1] ?? '');
            }
            if ($geoId === '') {
                continue;
            }

            $value = function (string $variable) use ($row, $idx): ?int {
                $raw = $row[$idx[$variable] ?? -1] ?? null;

                // ACS returns large negative sentinels (-666666666) for suppressed estimates — not data.
                return is_numeric($raw) && (int) $raw >= 0 ? (int) $raw : null;
            };
            $sum = function (string ...$variables) use ($value): ?int {
                $parts = array_map($value, $variables);

                return in_array(null, $parts, true) ? null : array_sum($parts);
            };

            $out[$geoId] = [
                'name' => (string) ($row[$idx['NAME'] ?? 0] ?? ''),
                'median_year_built' => $value('B25035_001E'),
                'occupied_units' => $value('B25003_001E'),
                'owner_occupied_units' => $value('B25003_002E'),
                'total_units' => $value('B25024_001E'),
                'single_family_units' => $sum('B25024_002E', 'B25024_003E'),
                'pre_1960_units' => $sum('B25034_009E', 'B25034_010E', 'B25034_011E'),
            ];
        }

        return $out;
    }
}
