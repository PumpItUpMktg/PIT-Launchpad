<?php

namespace App\Local\Grounding;

use App\Models\Location;

/**
 * Summer humidity for a location — the grounding a dehumidification or mold-testing page turns on.
 *
 * The mechanism is worth stating because it is what makes the number a fact rather than a statistic: a
 * basement wall sits at ground temperature, around 55–60°F through the summer. When the outdoor dew
 * point is higher than that, air moving through the space gives up its moisture on the wall. So a
 * regional dew point above the mid-60s is the difference between a basement that dries and one that
 * sweats, and it is the honest case for a dehumidifier.
 *
 * This is a LOCATION fact, deliberately: dew point is regional, and a per-town version would put the
 * same sentence on several hundred pages while implying a precision the measurement does not have. Each
 * town page instead carries what genuinely differs town to town — housing age, flood mapping, elevation,
 * soil drainage.
 */
final class HumidityProvider implements GroundingProvider
{
    /** Above this, summer air condenses on a basement wall; below it, the space dries by itself. */
    private const STICKY = 64.0;

    private const DRY = 55.0;

    // Injected, never constructed here: a hand-built HTTP client escapes Http::fake(), which is both a
    // test that lies and a provider that would really call NOAA from a unit test.
    public function __construct(private readonly NearestClimateStation $stations) {}

    public function fetch(Location $location): array
    {
        $source = 'noaa normals';
        $lat = $location->latitude ?? $location->lat;
        $lng = $location->longitude ?? $location->lng;
        if ($lat === null || $lng === null) {
            return ['facts' => [], 'source' => $source];
        }

        $nearest = $this->stations->for((float) $lat, (float) $lng);
        if ($nearest === null) {
            return ['facts' => [], 'source' => $source];
        }

        $station = $nearest['station'];
        $dewPoint = $station->summer_dew_point_f;
        if ($dewPoint === null) {
            return ['facts' => [], 'source' => $source];
        }
        $where = sprintf('%s, %.0f miles away', $this->stationName($station->name), $nearest['miles']);

        $facts = [sprintf(
            'Summer dew points here average %.0f°F (NOAA 1991–2020 normals, %s).',
            $dewPoint,
            $where,
        )];

        if ($dewPoint >= self::STICKY) {
            $facts[] = sprintf(
                'That is above the temperature of a basement wall in summer, so humid air gives up its moisture on the walls rather than drying out.',
            );
        } elseif ($dewPoint <= self::DRY) {
            $facts[] = 'That is below the temperature of a basement wall in summer, so the air dries the space rather than wetting it.';
        }

        return ['facts' => $facts, 'source' => $source];
    }

    /** "ALLENTOWN LEHIGH VALLEY INTL AP" is a NOAA label, not something to print at a homeowner. */
    private function stationName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return $name === '' ? 'the nearest long-term weather station' : ucwords(mb_strtolower($name));
    }
}
