<?php

namespace App\Locations\Dma;

/**
 * The county → DMA and state → location lookup tables (public Nielsen county↔DMA
 * assignments + DataForSEO location_name strings). Shipped as data files so a vintage
 * or catalog correction is a data edit, not a code change; the starter set covers the
 * NJ / eastern-PA calibration region and is extended per tenant region. Values are the
 * DataForSEO `location_name` strings — verify any addition against the DataForSEO
 * Google Ads locations catalog.
 */
final class DmaTable
{
    /**
     * @param  array<string, string>|null  $countyToDma  5-digit county FIPS → DMA location_name
     * @param  array<string, string>|null  $stateToLocation  2-letter state → state location_name
     */
    public function __construct(
        private ?array $countyToDma = null,
        private ?array $stateToLocation = null,
    ) {
        $this->countyToDma ??= self::load('county-dma.json');
        $this->stateToLocation ??= self::load('state-locations.json');
    }

    public function dmaForCounty(string $countyFips): ?string
    {
        return $this->countyToDma[$countyFips] ?? null;
    }

    public function locationForState(string $state): ?string
    {
        return $this->stateToLocation[strtoupper($state)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $file): array
    {
        $path = database_path('data/dma/'.$file);
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
