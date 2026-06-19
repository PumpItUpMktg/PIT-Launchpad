<?php

namespace App\Integrations\DataForSeo;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Resolves a covered metro / state `location_name` to a DataForSEO Google Ads
 * `location_code` against the (cached) locations catalog.
 *
 * The silo-volume pass used to pass constructed "City,ST" name strings straight to the
 * search-volume call; DataForSEO's location database didn't match them, so real DMAs
 * (New York, Philadelphia, Allentown) were silently skipped and only a bare state matched.
 * Numeric `location_code`s have no string-match fragility — this maps each metro to one,
 * **preferring the DMA Region**, then falling back to the state.
 *
 * Matching is structural: a name parses to (city tokens, 2-letter state); a DMA Region is
 * matched within the same state by exact city, then by city-token containment (so
 * "Allentown" finds the "Allentown-Bethlehem" DMA). A full state name (no 2-letter token,
 * e.g. "New Jersey") resolves to the State entry.
 */
final class DataForSeoLocations
{
    public function __construct(
        private readonly DataForSeoClient $client,
        private readonly Cache $cache,
        private readonly string $country = 'US',
        private readonly int $cacheDays = 30,
    ) {}

    public function resolve(string $name): ?int
    {
        [$city, $state] = $this->parse($name);
        $catalog = $this->catalog();

        if ($state !== null && $city !== '') {
            $dmas = $catalog['dma'][$state] ?? [];
            // 1) DMA Region, exact city within the same state.
            foreach ($dmas as $entry) {
                if ($entry['city'] === $city) {
                    return $entry['code'];
                }
            }
            // 2) DMA Region, city-token containment (Allentown ⊂ Allentown-Bethlehem).
            foreach ($dmas as $entry) {
                if ($entry['city'] !== '' && (str_contains($entry['city'], $city) || str_contains($city, $entry['city']))) {
                    return $entry['code'];
                }
            }
        }

        // 3) State-level fallback (the full state-name metros, e.g. "New Jersey,United States").
        $byFullName = $catalog['state'][$this->stateKey($name)] ?? null;
        if ($byFullName !== null) {
            return $byFullName;
        }

        // 4) Last resort: the metro's trailing postal state (so a DMA whose exact name isn't in
        // the catalog still grounds at the state level rather than dropping to zero).
        if ($state !== null && isset(self::POSTAL_TO_STATE[$state])) {
            return $catalog['state'][$this->norm(self::POSTAL_TO_STATE[$state])] ?? null;
        }

        return null;
    }

    /** 2-letter postal code → full state name (for the state-level fallback). */
    private const POSTAL_TO_STATE = [
        'al' => 'Alabama', 'ak' => 'Alaska', 'az' => 'Arizona', 'ar' => 'Arkansas', 'ca' => 'California',
        'co' => 'Colorado', 'ct' => 'Connecticut', 'de' => 'Delaware', 'dc' => 'District of Columbia',
        'fl' => 'Florida', 'ga' => 'Georgia', 'hi' => 'Hawaii', 'id' => 'Idaho', 'il' => 'Illinois',
        'in' => 'Indiana', 'ia' => 'Iowa', 'ks' => 'Kansas', 'ky' => 'Kentucky', 'la' => 'Louisiana',
        'me' => 'Maine', 'md' => 'Maryland', 'ma' => 'Massachusetts', 'mi' => 'Michigan', 'mn' => 'Minnesota',
        'ms' => 'Mississippi', 'mo' => 'Missouri', 'mt' => 'Montana', 'ne' => 'Nebraska', 'nv' => 'Nevada',
        'nh' => 'New Hampshire', 'nj' => 'New Jersey', 'nm' => 'New Mexico', 'ny' => 'New York',
        'nc' => 'North Carolina', 'nd' => 'North Dakota', 'oh' => 'Ohio', 'ok' => 'Oklahoma', 'or' => 'Oregon',
        'pa' => 'Pennsylvania', 'ri' => 'Rhode Island', 'sc' => 'South Carolina', 'sd' => 'South Dakota',
        'tn' => 'Tennessee', 'tx' => 'Texas', 'ut' => 'Utah', 'vt' => 'Vermont', 'va' => 'Virginia',
        'wa' => 'Washington', 'wv' => 'West Virginia', 'wi' => 'Wisconsin', 'wy' => 'Wyoming',
    ];

    /**
     * @return array{dma: array<string, list<array{city: string, code: int}>>, state: array<string, int>}
     */
    private function catalog(): array
    {
        /** @var array{dma: array<string, list<array{city: string, code: int}>>, state: array<string, int>} $catalog */
        $catalog = $this->cache->remember(
            "dataforseo:gads:locations:{$this->country}",
            now()->addDays($this->cacheDays),
            function (): array {
                $dma = [];
                $state = [];
                foreach ($this->client->googleAdsLocations($this->country) as $row) {
                    if (! is_array($row) || ! isset($row['location_code'], $row['location_name'], $row['location_type'])) {
                        continue;
                    }
                    $type = strtolower((string) $row['location_type']);
                    $code = (int) $row['location_code'];
                    $name = (string) $row['location_name'];

                    if ($type === 'dma region') {
                        [$city, $st] = $this->parse($name);
                        if ($st !== null) {
                            $dma[$st][] = ['city' => $city, 'code' => $code];
                        }
                    } elseif ($type === 'state') {
                        $state[$this->stateKey($name)] = $code;
                    }
                }

                return ['dma' => $dma, 'state' => $state];
            },
        );

        return $catalog;
    }

    /**
     * "City, ST, United States" / "City ST, United States" / "City ST" → [normalized city, 'st'];
     * a full state name (no 2-letter trailing token) → [normalized name, null]. The trailing
     * state may be separated by a comma OR a space — DataForSEO's DMA Region names are commonly
     * "City ST" (e.g. "Philadelphia PA"), not comma-delimited.
     *
     * @return array{0: string, 1: string|null}
     */
    private function parse(string $name): array
    {
        $name = (string) preg_replace('/,?\s*united states\s*$/i', '', trim($name));
        $name = trim($name, " \t,");

        $state = null;
        if (preg_match('/[,\s]([A-Za-z]{2})$/', $name, $m) === 1) {
            $state = strtolower($m[1]);
            $name = substr($name, 0, -strlen($m[0]));
        }

        return [$this->norm($name), $state];
    }

    private function stateKey(string $name): string
    {
        return $this->norm((string) preg_replace('/,?\s*united states\s*$/i', '', trim($name)));
    }

    private function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }
}
