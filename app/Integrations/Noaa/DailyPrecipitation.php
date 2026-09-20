<?php

namespace App\Integrations\Noaa;

use App\Local\Grounding\NearestClimateStation;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * NOAA's OBSERVED daily rainfall — what actually fell, on which day, at one station.
 *
 * The sibling of {@see HourlyNormals} and its opposite in purpose. Normals describe the climate a page is
 * written about ("summers here run humid"); this describes the weather a week actually had, so a spike in
 * a sump-pump client's search demand can be set against the storm that caused it. Neither number explains
 * the other on its own — shown together they let an operator say which of the two a good week was.
 *
 * Keyless and federal, the same `/access/services/data/v1` service the normals come from, so no new
 * credential and no new vendor. Values are inches; the station ids are GHCND, which is what the bundled
 * hourly-normals inventory already carries — the two datasets share an identifier space, so
 * {@see NearestClimateStation} resolves a station for this without a second list.
 *
 * NOAA publishes daily summaries on a LAG of roughly three days (verified live: on 2026-09-20 Allentown's
 * series ended 2026-09-17). Callers must not read the absence of the last few days as "no rain" — the
 * series simply has not been written yet, which is why this returns only the days NOAA actually reported
 * rather than zero-filling the window.
 */
class DailyPrecipitation
{
    private const URL = 'https://www.ncei.noaa.gov/access/services/data/v1';

    private const DATASET = 'daily-summaries';

    public function __construct(
        private readonly Http $http,
        private readonly int $timeout = 60,
    ) {}

    /**
     * Observed rainfall in inches per day, keyed Y-m-d, for the days NOAA reported inside the window.
     *
     * Missing days are ABSENT, not zero: a station that did not report is not a station that saw no rain,
     * and a chart that draws the difference as a dry spell is lying with real data.
     *
     * @return array<string, float>
     */
    public function forStation(string $stationId, Carbon $start, Carbon $end): array
    {
        $stationId = trim($stationId);
        if ($stationId === '' || $end->lessThan($start)) {
            return [];
        }

        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get(self::URL, [
                'dataset' => self::DATASET,
                'stations' => $stationId,
                'dataTypes' => 'PRCP',
                'startDate' => $start->toDateString(),
                'endDate' => $end->toDateString(),
                'format' => 'json',
                'units' => 'standard',   // inches, the unit a homeowner and a forecast both use
            ]);
        } catch (Throwable) {
            return [];
        }

        $rows = $response->successful() ? $response->json() : null;
        if (! is_array($rows)) {
            return [];
        }

        $series = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $row['DATE'] ?? null;
            $value = $row['PRCP'] ?? null;
            // NOAA marks a missing observation with -9999; negative rainfall is not weather.
            if (! is_string($date) || ! is_numeric($value) || (float) $value < 0.0) {
                continue;
            }
            $series[$date] = round((float) $value, 2);
        }

        ksort($series);

        return $series;
    }
}
