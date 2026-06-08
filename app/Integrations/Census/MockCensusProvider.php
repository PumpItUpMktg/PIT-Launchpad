<?php

namespace App\Integrations\Census;

/**
 * Deterministic Census mock for tests and the default binding.
 */
class MockCensusProvider implements CensusProvider
{
    /**
     * @return array<string, mixed>
     */
    public function demographics(string $geoId): array
    {
        return [
            'geo_id' => $geoId,
            'population' => 50000 + (abs(crc32($geoId)) % 450000),
            'median_household_income' => 45000 + (abs(crc32('income'.$geoId)) % 80000),
            'owner_occupied_rate' => round(0.45 + (abs(crc32('own'.$geoId)) % 40) / 100, 2),
        ];
    }
}
