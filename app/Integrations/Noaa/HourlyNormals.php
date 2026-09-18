<?php

namespace App\Integrations\Noaa;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * NOAA's 1991–2020 hourly climate normals — specifically the SUMMER DEW POINT, which is the number a
 * dehumidification or mold page turns on.
 *
 * Dew point, not relative humidity: relative humidity is a ratio that moves with temperature, so "70%"
 * says nothing without saying 70% of what. Dew point is the temperature at which air gives up its
 * moisture, so it compares directly against a cool basement wall — which is the whole mechanism a
 * homeowner is living with.
 *
 * Keyless and federal. Verified live before this was written: Allentown's station returns 744 hourly
 * values for July and a 63.2°F mean.
 *
 * Open-Meteo would also serve this and was rejected on evidence: its free tier is rate-limited per IP
 * and this control plane calls from one address for every tenant. It answered "Daily API request limit
 * exceeded" twice while this was being built — the same cap `ClimateNormalsProvider` already runs
 * against.
 */
class HourlyNormals
{
    private const URL = 'https://www.ncei.noaa.gov/access/services/data/v1';

    private const DATASET = 'normals-hourly-1991-2020';

    public function __construct(
        private readonly Http $http,
        private readonly int $timeout = 60,
    ) {}

    /**
     * The mean dew point across July and August at one station, in °F, or null when the station has no
     * usable normal.
     *
     * The peak two months rather than the year: a page about basements sweating is about the season it
     * happens in, and an annual mean would average it away against February.
     */
    public function summerDewPoint(string $stationId): ?float
    {
        $stationId = trim($stationId);
        if ($stationId === '') {
            return null;
        }

        $values = [];
        // The normals carry a placeholder year; the range is what selects the months.
        foreach ([['2010-07-01', '2010-07-31'], ['2010-08-01', '2010-08-31']] as [$start, $end]) {
            foreach ($this->fetch($stationId, $start, $end) as $value) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    /**
     * @return list<float>
     */
    private function fetch(string $stationId, string $start, string $end): array
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get(self::URL, [
                'dataset' => self::DATASET,
                'stations' => $stationId,
                'dataTypes' => 'HLY-DEWP-NORMAL',
                'startDate' => $start,
                'endDate' => $end,
                'format' => 'json',
                'units' => 'standard',   // °F, the unit the copy uses
            ]);
        } catch (Throwable) {
            return [];
        }

        $rows = $response->successful() ? $response->json() : null;
        if (! is_array($rows)) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $value = is_array($row) ? ($row['HLY-DEWP-NORMAL'] ?? null) : null;
            // NOAA marks a missing normal with -9999; a dew point that low is not weather.
            if (is_numeric($value) && (float) $value > -100.0) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }
}
