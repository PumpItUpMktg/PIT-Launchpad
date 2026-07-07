<?php

namespace App\Publishing\Blocks;

/**
 * Maps a service name to a curated icon slug — deterministic, no generation, no weird-image risk. The
 * icon is emitted as a CLASS (`lp-icon lp-icon--{slug}`) and drawn by the theme's CSS, NOT as inline
 * SVG: WordPress' `kses` strips <svg> from post_content on save (the service user has no unfiltered_html
 * cap), which is why inline-SVG icons would render as EMPTY spans. A class survives kses.
 *
 * MULTI-TENANT by design: the keyword table spans the trades the platform serves (plumbing, HVAC,
 * electrical, roofing, landscaping, painting, flooring, cleaning, pest, appliances, garage doors,
 * windows, fencing, concrete/masonry, locksmith, security, automotive, tree, solar, handyman, …), so a
 * tenant in any of them gets relevant icons rather than a wall of generic marks. Anything unmatched
 * resolves to the `service` FALLBACK, and every slug this class can emit is styled by the theme
 * (guarded by a contract test) — so a card icon is NEVER empty, whatever the service set.
 */
final class ServiceIcon
{
    /**
     * keyword => icon slug. First match wins, so order is SPECIFIC → generic and every substring
     * collision is deliberate. Examples of the ordering rules baked in here:
     *  - `water heater`/`tankless` resolve before the generic `water`/`heating`;
     *  - `heat pump` resolves to HVAC before the plumbing `pump`;
     *  - `solar` resolves before the electrical `panel` ("solar panel");
     *  - `pressure wash` resolves before the appliance `washer`;
     *  - there is no bare `main` keyword (it lives inside "maintenance") — "water main" is explicit.
     */
    private const KEYWORDS = [
        // Solar — before electrical 'panel' ("solar panel")
        'solar' => 'solar', 'photovolt' => 'solar',

        // Drain cleaning / clogs — before the generic 'clean'
        'drain' => 'drain', 'clog' => 'drain', 'snak' => 'drain', 'rooter' => 'drain', 'auger' => 'drain', 'cleanout' => 'drain',

        // Hydro jetting
        'hydro' => 'jet', 'jet' => 'jet',

        // Camera / inspection (also covers a security tenant's "camera")
        'camera' => 'camera', 'inspect' => 'camera', 'scope' => 'camera', 'video' => 'camera', 'surveillance' => 'camera',

        // Water heaters — before generic water / heating / install
        'water heater' => 'heater', 'tankless' => 'heater', 'boiler' => 'heater', 'heater' => 'heater',

        // Toilets
        'toilet' => 'toilet', 'commode' => 'toilet', 'urinal' => 'toilet',

        // Faucets / fixtures / sinks / showers
        'faucet' => 'faucet', 'fixture' => 'faucet', 'sink' => 'faucet', 'shower' => 'faucet', 'bathtub' => 'faucet', 'bath' => 'faucet',

        // HVAC — before the plumbing 'pump' ("heat pump") and the generic 'heating'
        'hvac' => 'hvac', 'air condition' => 'hvac', 'furnace' => 'hvac', 'heat pump' => 'hvac',
        'mini split' => 'hvac', 'mini-split' => 'hvac', 'ductless' => 'hvac', 'duct' => 'hvac',
        'thermostat' => 'hvac', 'cooling' => 'hvac', 'heating' => 'hvac', 'ventilation' => 'hvac', 'a/c' => 'hvac',

        // Gas — before 'line' ("gas line")
        'gas' => 'gas', 'propane' => 'gas',

        // Sump / ejector / well pumps
        'sump' => 'pump', 'ejector' => 'pump', 'grinder' => 'pump', 'well pump' => 'pump', 'pump' => 'pump',

        // Grease traps / interceptors
        'grease' => 'grease', 'interceptor' => 'grease', 'trap' => 'grease',

        // Sewer / pipe / water lines
        'sewer' => 'pipe', 'trenchless' => 'pipe', 'repipe' => 'pipe', 'water main' => 'pipe', 'plumb' => 'pipe', 'pipe' => 'pipe', 'line' => 'pipe',

        // Leak detection / water treatment
        'leak' => 'droplet', 'soften' => 'droplet', 'filtr' => 'droplet', 'purif' => 'droplet',
        'condition' => 'droplet', 'backflow' => 'droplet', 'water' => 'droplet',

        // Electrical — after solar
        'electric' => 'electric', 'wiring' => 'electric', 'rewire' => 'electric', 'outlet' => 'electric',
        'breaker' => 'electric', 'panel' => 'electric', 'lighting' => 'electric', 'generator' => 'electric', 'ev charg' => 'electric',

        // Roofing
        'roof' => 'roof', 'shingle' => 'roof', 'gutter' => 'roof', 'soffit' => 'roof', 'fascia' => 'roof',

        // Tree — before landscaping
        'tree' => 'tree', 'stump' => 'tree', 'arborist' => 'tree',

        // Lawn / landscaping
        'lawn' => 'lawn', 'landscap' => 'lawn', 'mow' => 'lawn', 'garden' => 'lawn', 'mulch' => 'lawn',
        'sod' => 'lawn', 'turf' => 'lawn', 'irrigation' => 'lawn', 'sprinkler' => 'lawn', 'yard' => 'lawn',

        // Painting
        'paint' => 'paint', 'stain' => 'paint', 'primer' => 'paint',

        // Cleaning — before flooring 'carpet' and windows
        'pressure wash' => 'clean', 'power wash' => 'clean', 'housekeep' => 'clean', 'maid' => 'clean',
        'janitor' => 'clean', 'carpet clean' => 'clean', 'clean' => 'clean',

        // Chimney sweep
        'chimney' => 'broom', 'sweep' => 'broom',

        // Windows / glass
        'window' => 'window', 'glass' => 'window', 'skylight' => 'window',

        // Flooring
        'floor' => 'floor', 'tile' => 'floor', 'hardwood' => 'floor', 'laminate' => 'floor', 'vinyl' => 'floor', 'grout' => 'floor', 'carpet' => 'floor',

        // Pest control
        'pest' => 'pest', 'exterm' => 'pest', 'termite' => 'pest', 'rodent' => 'pest', 'reptile' => 'pest',
        'insect' => 'pest', 'mosquito' => 'pest', 'wildlife' => 'pest', 'bed bug' => 'pest',

        // Appliance repair
        'appliance' => 'appliance', 'refriger' => 'appliance', 'fridge' => 'appliance', 'washer' => 'appliance',
        'dryer' => 'appliance', 'dishwasher' => 'appliance', 'oven' => 'appliance', 'stove' => 'appliance', 'microwave' => 'appliance',

        // Garage doors
        'garage' => 'garage', 'overhead door' => 'garage',

        // Fencing / gates / railings
        'fence' => 'fence', 'fencing' => 'fence', 'gate' => 'fence', 'railing' => 'fence',

        // Concrete / masonry
        'concrete' => 'concrete', 'mason' => 'concrete', 'brick' => 'concrete', 'paver' => 'concrete',
        'cement' => 'concrete', 'stucco' => 'concrete', 'foundation' => 'concrete', 'driveway' => 'concrete', 'patio' => 'concrete',

        // Locksmith
        'locksmith' => 'lock', 'deadbolt' => 'lock', 'rekey' => 'lock', 'lock' => 'lock',

        // Security
        'security' => 'shield', 'alarm' => 'shield', 'guard' => 'shield',

        // Automotive
        'automotive' => 'auto', 'vehicle' => 'auto', 'auto' => 'auto', 'tire' => 'auto',
        'brake' => 'auto', 'transmission' => 'auto', 'muffler' => 'auto', 'windshield' => 'auto',

        // Handyman / carpentry / remodeling
        'handyman' => 'tools', 'carpent' => 'tools', 'remodel' => 'tools', 'renovation' => 'tools', 'assembly' => 'tools', 'deck' => 'tools',

        // Emergency
        'emergency' => 'bolt', '24' => 'bolt', 'urgent' => 'bolt',

        // General repair / install / service
        'repair' => 'wrench', 'replace' => 'wrench', 'install' => 'wrench', 'maintenance' => 'wrench', 'excavat' => 'wrench', 'service' => 'wrench',
    ];

    public const FALLBACK = 'service';

    public function slugFor(string $title): string
    {
        $t = mb_strtolower(trim($title));
        if ($t === '') {
            return self::FALLBACK;
        }

        foreach (self::KEYWORDS as $keyword => $slug) {
            if (str_contains($t, $keyword)) {
                return $slug;
            }
        }

        return self::FALLBACK;
    }

    /**
     * Every icon slug this mapper can EMIT (all keyword targets + the fallback), deduped. The theme must
     * style each one; a contract test asserts exactly that, so the mapping and the theme can never drift
     * into an empty icon for any service.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_values(array_unique([...array_values(self::KEYWORDS), self::FALLBACK]));
    }
}
