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
    /** keyword => icon slug. First match wins; order matters (specific before generic). */
    private const KEYWORDS = [
        'drain' => 'drain', 'clog' => 'drain', 'snak' => 'drain', 'rooter' => 'drain',
        'jet' => 'jet', 'hydro' => 'jet',
        'sewer' => 'pipe', 'pipe' => 'pipe', 'line' => 'pipe', 'trenchless' => 'pipe', 'main' => 'pipe',
        'camera' => 'camera', 'inspect' => 'camera', 'scope' => 'camera', 'video' => 'camera', 'locat' => 'camera',
        'leak' => 'droplet', 'detect' => 'droplet',
        'water heater' => 'droplet', 'heater' => 'droplet', 'soften' => 'droplet', 'filtr' => 'droplet',
        'condition' => 'droplet', 'backflow' => 'droplet', 'water' => 'droplet',
        'sump' => 'pump', 'pump' => 'pump', 'ejector' => 'pump', 'grinder' => 'pump',
        'grease' => 'grease', 'trap' => 'grease',
        'emergency' => 'bolt', '24' => 'bolt', 'urgent' => 'bolt',
        'repair' => 'wrench', 'replace' => 'wrench', 'install' => 'wrench', 'maintenance' => 'wrench',
        'excavat' => 'wrench', 'gas' => 'wrench', 'fixture' => 'wrench', 'faucet' => 'wrench', 'toilet' => 'wrench',
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
