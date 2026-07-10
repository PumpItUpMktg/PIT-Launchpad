<?php

namespace App\Local\Grounding;

use App\Models\Location;
use Illuminate\Support\Facades\Http;

/**
 * Elevation per served town (Google Elevation API) — terrain / low-lying context for trades where
 * grade matters (waterproofing, drainage). Skips cleanly without a Maps key.
 */
final class GoogleElevationProvider implements GroundingProvider
{
    private const URL = 'https://maps.googleapis.com/maps/api/elevation/json';

    public function fetch(Location $location): array
    {
        $key = (string) config('services.google.maps_api_key', '');
        $towns = collect(is_array($location->served_towns) ? $location->served_towns : [])
            ->filter(fn (array $t): bool => isset($t['lat'], $t['lng']))
            ->take(12)
            ->values();
        if ($key === '' || $towns->isEmpty()) {
            return ['facts' => [], 'source' => 'google elevation'];
        }

        $response = Http::timeout(10)->get(self::URL, [
            'locations' => $towns->map(fn (array $t): string => $t['lat'].','.$t['lng'])->implode('|'),
            'key' => $key,
        ]);
        $results = $response->successful() ? ($response->json('results') ?? []) : [];
        if (! is_array($results) || $results === []) {
            return ['facts' => [], 'source' => 'google elevation'];
        }

        $feet = [];
        foreach ($results as $i => $r) {
            if (isset($r['elevation']) && is_numeric($r['elevation'])) {
                $feet[(string) ($towns[$i]['name'] ?? '')] = (float) $r['elevation'] * 3.28084;
            }
        }
        if ($feet === []) {
            return ['facts' => [], 'source' => 'google elevation'];
        }

        asort($feet);
        $low = array_key_first($feet);
        $high = array_key_last($feet);
        $facts = [sprintf(
            'Elevations across the service area run from about %.0f ft (%s) to %.0f ft (%s).',
            $feet[$low], $low, $feet[$high], $high,
        )];
        if ($feet[$low] < 150) {
            $facts[] = sprintf('%s sits low (~%.0f ft) — the kind of terrain where water finds basements.', $low, $feet[$low]);
        }

        return ['facts' => $facts, 'source' => 'google elevation'];
    }
}
