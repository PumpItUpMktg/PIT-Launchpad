<?php

namespace App\Local\Grounding;

use App\Models\Location;
use Illuminate\Support\Facades\Http;

/**
 * Seasonal climate NORMALS for the location point — decade-scale monthly means from Open-Meteo's
 * archive API (free, no key). Deliberately NOT a live weather API: its history window is hours, and
 * seasonal claims ("wet springs", "freeze-thaw winters") need normals, not yesterday's forecast.
 */
final class ClimateNormalsProvider implements GroundingProvider
{
    private const URL = 'https://archive-api.open-meteo.com/v1/archive';

    public function fetch(Location $location): array
    {
        $lat = $location->latitude ?? $location->lat;
        $lng = $location->longitude ?? $location->lng;
        if ($lat === null || $lng === null) {
            return ['facts' => [], 'source' => 'open-meteo archive'];
        }

        $response = Http::timeout(10)->get(self::URL, [
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'start_date' => now()->subYears(10)->startOfYear()->toDateString(),
            'end_date' => now()->subYear()->endOfYear()->toDateString(),
            'monthly' => 'precipitation_sum,temperature_2m_mean',
            'precipitation_unit' => 'inch',
            'temperature_unit' => 'fahrenheit',
        ]);
        if (! $response->successful()) {
            return ['facts' => [], 'source' => 'open-meteo archive'];
        }

        $monthly = $response->json('monthly') ?? [];
        $months = $monthly['time'] ?? [];
        $precip = $monthly['precipitation_sum'] ?? [];
        $temp = $monthly['temperature_2m_mean'] ?? [];
        if (! is_array($months) || $months === []) {
            return ['facts' => [], 'source' => 'open-meteo archive'];
        }

        // Collapse the decade to per-calendar-month means, then to seasonal statements.
        $byMonth = array_fill(1, 12, ['p' => [], 't' => []]);
        foreach ($months as $i => $ym) {
            $m = (int) substr((string) $ym, 5, 2);
            if ($m >= 1 && $m <= 12) {
                if (isset($precip[$i]) && is_numeric($precip[$i])) {
                    $byMonth[$m]['p'][] = (float) $precip[$i];
                }
                if (isset($temp[$i]) && is_numeric($temp[$i])) {
                    $byMonth[$m]['t'][] = (float) $temp[$i];
                }
            }
        }
        $avg = fn (array $v): ?float => $v === [] ? null : array_sum($v) / count($v);
        $season = function (array $monthsIn) use ($byMonth, $avg): array {
            $p = $t = [];
            foreach ($monthsIn as $m) {
                if (($v = $avg($byMonth[$m]['p'])) !== null) {
                    $p[] = $v;
                }
                if (($v = $avg($byMonth[$m]['t'])) !== null) {
                    $t[] = $v;
                }
            }

            return [$avg($p), $avg($t)];
        };

        $facts = [];
        [$springP] = $season([3, 4, 5]);
        [$summerP] = $season([6, 7, 8]);
        [, $winterT] = $season([12, 1, 2]);
        $annualP = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $annualP += $avg($byMonth[$m]['p']) ?? 0.0;
        }

        if ($annualP > 0) {
            $facts[] = sprintf('Average annual precipitation is about %.0f inches.', $annualP);
        }
        if ($springP !== null && $summerP !== null && $springP > $summerP * 1.15) {
            $facts[] = sprintf('Spring is the wettest stretch (~%.1f in/month on average).', $springP);
        }
        if ($winterT !== null && $winterT < 36) {
            $facts[] = sprintf('Winters average around %.0f°F, with regular freeze–thaw cycles.', $winterT);
        }

        return ['facts' => $facts, 'source' => 'open-meteo archive (10-year normals)'];
    }
}
