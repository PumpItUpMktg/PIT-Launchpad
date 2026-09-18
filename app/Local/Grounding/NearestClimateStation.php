<?php

namespace App\Local\Grounding;

use App\Integrations\Noaa\HourlyNormals;
use App\Models\ClimateStation;
use Illuminate\Support\Carbon;

/**
 * The NOAA station whose summer humidity normal describes a location, and how far away it is.
 *
 * The station list is BUNDLED (`database/data/climate/hourly-normals-stations.csv`, all 467 stations in
 * the hourly-normals network). NOAA publishes it as a static inventory, so shipping it beats calling a
 * search API that can be down or change its query shape; the nearest station is then arithmetic, not a
 * request.
 *
 * Only the chosen station's normal is fetched, once, and kept: a territory of seven hundred towns is a
 * handful of stations, and dew point does not move between neighbouring townships.
 */
final class NearestClimateStation
{
    /** Beyond this the station is not describing the same weather, and we say nothing rather than guess. */
    private const MAX_MILES = 60.0;

    public function __construct(private readonly HourlyNormals $normals) {}

    /**
     * @return array{station: ClimateStation, miles: float}|null
     */
    public function for(float $lat, float $lng): ?array
    {
        $nearest = null;
        $nearestMiles = PHP_FLOAT_MAX;
        foreach ($this->stations() as $station) {
            $miles = $this->haversine($lat, $lng, $station['lat'], $station['lng']);
            if ($miles < $nearestMiles) {
                $nearest = $station;
                $nearestMiles = $miles;
            }
        }
        if ($nearest === null || $nearestMiles > self::MAX_MILES) {
            return null;
        }

        $row = ClimateStation::query()->where('station_id', $nearest['station_id'])->first();
        if ($row === null) {
            $row = ClimateStation::query()->create([
                'station_id' => $nearest['station_id'],
                'name' => $nearest['name'],
                'state' => $nearest['state'] !== '' ? $nearest['state'] : null,
                'lat' => $nearest['lat'],
                'lng' => $nearest['lng'],
                'summer_dew_point_f' => $this->normals->summerDewPoint($nearest['station_id']),
                'fetched_at' => Carbon::now(),
            ]);
        }

        return ['station' => $row, 'miles' => round($nearestMiles, 1)];
    }

    /**
     * The bundled inventory, parsed once per request.
     *
     * @return list<array{station_id: string, lat: float, lng: float, state: string, name: string}>
     */
    private function stations(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $path = database_path('data/climate/hourly-normals-stations.csv');
        $cached = [];
        if (! is_readable($path)) {
            return $cached;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $cached;
        }
        fgetcsv($handle, 0, ',', '"', '\\');   // header
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) < 5 || ! is_numeric($row[1]) || ! is_numeric($row[2])) {
                continue;
            }
            $cached[] = [
                'station_id' => (string) $row[0],
                'lat' => (float) $row[1],
                'lng' => (float) $row[2],
                'state' => (string) $row[3],
                'name' => (string) $row[4],
            ];
        }
        fclose($handle);

        return $cached;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 3958.8 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
