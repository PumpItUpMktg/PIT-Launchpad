<?php

namespace App\Metrics\Providers;

use App\Integrations\Noaa\DailyPrecipitation;
use App\Local\Grounding\NearestClimateStation;
use App\Metrics\Contracts\MetricProvider;
use App\Metrics\SyncResult;
use App\Models\Location;
use App\Models\MetricSnapshot;
use App\Models\Site;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The WEATHER slice of the metric spine — observed daily rainfall, in inches, at the NOAA stations that
 * describe a site's territory (provider=noaa, metric_key=precipitation_in, dimension=station).
 *
 * Why a trade platform stores rain: for a sump-pump, waterproofing or roofing client, demand is partly
 * weather. A week where calls double after four inches of rain and a week where they double after a dry
 * spell are the same line on a traffic chart and completely different facts about the business. Storing
 * the rainfall on the same spine, at the same daily grain, lets a trend be read against the world it
 * happened in instead of in a vacuum.
 *
 * It is CONTEXT, never attribution. Nothing here computes a lift, a share or a causal claim — it writes
 * one honest number per day so a chart can put two series side by side and let a human read them.
 *
 * Keyed on the STATION, not the location: a site spanning three states has genuinely different weather
 * across it, but neighbouring branches usually share one station, and a row per location would store the
 * same observation several times under different names. Stations are resolved once per sync from the
 * site's geocoded locations and deduped, so a ten-branch tenant is a handful of federal requests.
 *
 * A clean no-op (0 rows) for a site with no geocoded location or none within reach of a station — a
 * tenant we cannot honestly describe the weather for gets no weather, not a guess from 200 miles away.
 * Idempotent on {@see MetricSnapshot::GRAIN_KEYS}.
 */
class WeatherMetricProvider implements MetricProvider
{
    public const PROVIDER = 'noaa';

    public const METRIC = 'precipitation_in';

    public function __construct(
        private readonly NearestClimateStation $stations,
        private readonly DailyPrecipitation $precipitation,
    ) {}

    public function key(): string
    {
        return self::PROVIDER;
    }

    public function sync(Site $site, CarbonPeriod $range): SyncResult
    {
        $stations = $this->stationsFor($site);
        if ($stations === []) {
            return SyncResult::success(0);
        }

        $start = Carbon::parse($range->getStartDate());
        $end = Carbon::parse($range->getEndDate());
        $now = Carbon::now();

        $upserts = [];
        foreach ($stations as $stationId => $label) {
            foreach ($this->precipitation->forStation($stationId, $start, $end) as $date => $inches) {
                $upserts[] = [
                    'id' => (string) Str::ulid(),
                    'site_id' => $site->id,
                    'provider' => self::PROVIDER,
                    'metric_key' => self::METRIC,
                    'dimension_type' => 'station',
                    'dimension_value' => $stationId,
                    'period_grain' => 'day',
                    'period_date' => $date,
                    'value_numeric' => $inches,
                    // The human name travels with the number so a chart can label its own axis without
                    // re-resolving the station inventory.
                    'value_json' => json_encode(['station' => $label]),
                    'captured_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($upserts === []) {
            return SyncResult::success(0);
        }

        DB::table('metric_snapshots')->upsert(
            $upserts,
            MetricSnapshot::GRAIN_KEYS,
            ['value_numeric', 'value_json', 'captured_at', 'updated_at'],
        );

        return SyncResult::success(count($upserts));
    }

    /**
     * The distinct stations covering the site's geocoded locations, keyed by GHCND id → display name.
     *
     * @return array<string, string>
     */
    private function stationsFor(Site $site): array
    {
        /** @var Collection<int, Location> $locations */
        $locations = Location::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->whereNull('merged_into_id')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get();

        $stations = [];
        foreach ($locations as $location) {
            $nearest = $this->stations->for((float) $location->lat, (float) $location->lng);
            if ($nearest === null) {
                continue;
            }
            $station = $nearest['station'];
            $stations[$station->station_id] = $station->name;
        }

        return $stations;
    }
}
