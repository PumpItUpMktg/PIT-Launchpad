<?php

namespace Tests\Support;

use App\Enums\ServiceSiloRole;
use App\Models\Service;
use App\Models\ServiceProblem;
use App\Models\Site;

/**
 * Seeds a Service Catalog for Silo Creator tests: two pillar services with
 * problem inventories and one supporting service.
 */
class SiloCreatorFixtures
{
    /**
     * @return array{site: Site, plumbing: Service, hvac: Service, supporting: Service}
     */
    public static function catalog(): array
    {
        $site = Site::factory()->create();

        $plumbing = Service::factory()->create([
            'site_id' => $site->id,
            'name' => 'Plumbing',
            'silo_role' => ServiceSiloRole::Pillar,
            'scope' => 'Repair and installation of pipes, fixtures and water heaters',
        ]);
        ServiceProblem::factory()->create(['service_id' => $plumbing->id, 'phrase' => 'water heater leaking']);
        ServiceProblem::factory()->create(['service_id' => $plumbing->id, 'phrase' => 'clogged drain backing up']);
        ServiceProblem::factory()->create(['service_id' => $plumbing->id, 'phrase' => 'low water pressure']);

        $hvac = Service::factory()->create([
            'site_id' => $site->id,
            'name' => 'HVAC',
            'silo_role' => ServiceSiloRole::Pillar,
            'scope' => 'Heating and cooling repair, maintenance and replacement',
        ]);
        ServiceProblem::factory()->create(['service_id' => $hvac->id, 'phrase' => 'furnace not heating']);
        ServiceProblem::factory()->create(['service_id' => $hvac->id, 'phrase' => 'air conditioner blowing warm']);

        $supporting = Service::factory()->create([
            'site_id' => $site->id,
            'name' => 'Drain Cleaning',
            'silo_role' => ServiceSiloRole::Supporting,
        ]);
        ServiceProblem::factory()->create(['service_id' => $supporting->id, 'phrase' => 'recurring sewer clog']);

        return ['site' => $site, 'plumbing' => $plumbing, 'hvac' => $hvac, 'supporting' => $supporting];
    }

    /**
     * A canned topical-clustering response referencing the seeded problems.
     */
    public static function themesJson(): string
    {
        return json_encode([
            'themes' => [
                [
                    'name' => 'Maintenance & Prevention',
                    'terms' => ['maintenance', 'prevention', 'inspection'],
                    'problems' => ['water heater leaking', 'low water pressure', 'furnace not heating'],
                    'keywords' => [],
                ],
                [
                    // Under-supported: only one match — should be dropped by the guard.
                    'name' => 'Smart Thermostats',
                    'terms' => ['thermostat'],
                    'problems' => ['air conditioner blowing warm'],
                    'keywords' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
