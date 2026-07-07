<?php

namespace App\Publishing\Blocks;

/**
 * Maps a service name to a curated icon slug — deterministic, no generation, no weird-image risk. The
 * icon is emitted as a CLASS (`lp-icon lp-icon--{slug}`) and drawn by the theme's CSS, NOT as inline
 * SVG: WordPress' `kses` strips <svg> from post_content on save (the service user has no unfiltered_html
 * cap), which is exactly why the current inline-SVG icons render as EMPTY spans. A class survives kses.
 *
 * Every service resolves to a slug in the bounded set the theme styles — unmatched → the `service`
 * fallback — so a card icon is never empty.
 */
final class ServiceIcon
{
    /**
     * keyword => icon slug. First match wins; order matters (SPECIFIC before generic), and every
     * substring collision is deliberate — e.g. `water heater`/`tankless` resolve BEFORE the generic
     * `water`/`heater`, and `maintenance` resolves before the bare pipe keywords (which is why there's
     * no bare `main` keyword — it lives inside "maintenance"). Every slug here is styled by the theme.
     */
    private const KEYWORDS = [
        // Drain cleaning / clogs
        'drain' => 'drain', 'clog' => 'drain', 'snak' => 'drain', 'rooter' => 'drain', 'auger' => 'drain',
        // Hydro jetting
        'hydro' => 'jet', 'jet' => 'jet',
        // Camera / inspection
        'camera' => 'camera', 'inspect' => 'camera', 'scope' => 'camera', 'video' => 'camera', 'locat' => 'camera',
        // Water heaters (before the generic water / heater / install keywords)
        'water heater' => 'heater', 'tankless' => 'heater', 'heater' => 'heater', 'boiler' => 'heater',
        // Toilets
        'toilet' => 'toilet', 'commode' => 'toilet',
        // Faucets / fixtures / sinks
        'faucet' => 'faucet', 'fixture' => 'faucet', 'sink' => 'faucet', 'shower' => 'faucet', 'bath' => 'faucet',
        // Gas lines (before pipe, so "gas line" reads as gas)
        'gas' => 'gas', 'propane' => 'gas',
        // Sump / ejector pumps
        'sump' => 'pump', 'ejector' => 'pump', 'grinder' => 'pump', 'pump' => 'pump',
        // Grease traps / interceptors
        'grease' => 'grease', 'interceptor' => 'grease', 'trap' => 'grease',
        // Sewer / pipe / water lines
        'sewer' => 'pipe', 'trenchless' => 'pipe', 'repipe' => 'pipe', 'water main' => 'pipe',
        'pipe' => 'pipe', 'line' => 'pipe',
        // Leak detection / water treatment
        'leak' => 'droplet', 'detect' => 'droplet', 'soften' => 'droplet', 'filtr' => 'droplet',
        'condition' => 'droplet', 'backflow' => 'droplet', 'water' => 'droplet',
        // Emergency
        'emergency' => 'bolt', '24' => 'bolt', 'urgent' => 'bolt',
        // General repair / install / service
        'repair' => 'wrench', 'replace' => 'wrench', 'install' => 'wrench', 'maintenance' => 'wrench', 'excavat' => 'wrench',
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
}
