<?php

namespace App\Integrations\Gbp;

/**
 * Deterministic GBP mock for tests and the default binding (no vendor
 * committed). Returns a canned service-type checklist per category.
 */
class MockGbpProvider implements GbpProvider
{
    private const CATALOG = [
        'plumber' => ['Water Heater Repair', 'Drain Cleaning', 'Leak Detection', 'Pipe Repair', 'Sewer Line Service'],
        'hvac_contractor' => ['AC Repair', 'Furnace Repair', 'HVAC Installation', 'Duct Cleaning'],
        'electrician' => ['Panel Upgrade', 'Wiring Repair', 'Lighting Installation', 'EV Charger Installation'],
    ];

    /**
     * @return list<string>
     */
    public function serviceTypes(string $primaryCategory): array
    {
        return self::CATALOG[$primaryCategory] ?? ['General Service', 'Repair', 'Installation', 'Maintenance'];
    }
}
