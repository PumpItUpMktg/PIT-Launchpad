<?php

namespace App\Operate;

use App\Integrations\Noaa\DailyPrecipitation;
use App\Metrics\Providers\WeatherMetricProvider;
use App\Models\MetricSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * The observed-rainfall series a result trend is read against — the read half of
 * {@see WeatherMetricProvider}.
 *
 * It answers one question honestly: how much rain fell over this tenant's territory, on each day, and
 * through what date do we actually know. It does NOT attribute traffic to weather. The strongest thing it
 * will say is {@see correlate()}'s coefficient, which is labelled a correlation because that is all it is
 * — two series moving together is not one causing the other, and a wet week that also happened to be the
 * week a big page got indexed looks identical either way.
 *
 * A site spanning several weather areas is averaged, not summed: the territory saw "about an inch", not
 * the three inches that adding up three stations would claim.
 */
final class RainfallSeries
{
    /** Below this many overlapping days a coefficient is noise dressed up as a number. */
    private const MIN_PAIRS = 14;

    /**
     * Daily rainfall over the window, keyed Y-m-d → inches, averaged across the site's stations.
     *
     * Days NOAA has not published are ABSENT rather than zero — see {@see DailyPrecipitation}.
     *
     * @return array<string, float>
     */
    public function daily(Site $site, Carbon $since, ?Carbon $until = null): array
    {
        $rows = MetricSnapshot::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->where('provider', WeatherMetricProvider::PROVIDER)
            ->where('metric_key', WeatherMetricProvider::METRIC)
            ->where('period_grain', 'day')
            ->where('period_date', '>=', $since->toDateString())
            ->when($until !== null, fn ($q) => $q->where('period_date', '<=', $until->toDateString()))
            ->selectRaw('period_date, avg(value_numeric) as inches')
            ->groupBy('period_date')
            ->orderBy('period_date')
            ->get();

        $series = [];
        foreach ($rows as $row) {
            $date = Carbon::parse((string) $row->period_date)->toDateString();
            $series[$date] = round((float) $row->inches, 2);
        }

        return $series;
    }

    /**
     * The stations standing in for this site's weather, id → name, so a chart or report can say whose rain
     * it is showing rather than presenting an unsourced number.
     *
     * @return array<string, string>
     */
    public function stations(Site $site): array
    {
        $rows = MetricSnapshot::query()->withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('provider', WeatherMetricProvider::PROVIDER)
            ->where('metric_key', WeatherMetricProvider::METRIC)
            ->select(['dimension_value', 'value_json'])
            ->distinct()
            ->get();

        $stations = [];
        foreach ($rows as $row) {
            $json = $row->value_json;
            $name = is_array($json) && isset($json['station']) && is_string($json['station'])
                ? $json['station']
                : (string) $row->dimension_value;
            $stations[(string) $row->dimension_value] = $name;
        }

        return $stations;
    }

    /** The most recent day NOAA has actually reported for this site, or null when nothing is stored. */
    public function through(Site $site): ?Carbon
    {
        $latest = MetricSnapshot::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->where('provider', WeatherMetricProvider::PROVIDER)
            ->where('metric_key', WeatherMetricProvider::METRIC)
            ->max('period_date');

        return is_string($latest) || is_int($latest) ? Carbon::parse((string) $latest) : null;
    }

    /**
     * Pearson correlation between rainfall and another daily series over the days BOTH cover.
     *
     * Null when the overlap is too thin ({@see MIN_PAIRS}) or either series never varies — a flat series
     * has no correlation to report, and printing 0.0 would read as "no relationship" rather than
     * "nothing to compare".
     *
     * @param  array<string, float>  $rain
     * @param  array<string, float>  $other
     * @return array{r: float, days: int}|null
     */
    public function correlate(array $rain, array $other): ?array
    {
        $x = [];
        $y = [];
        foreach ($rain as $date => $inches) {
            if (array_key_exists($date, $other)) {
                $x[] = $inches;
                $y[] = $other[$date];
            }
        }

        $n = count($x);
        if ($n < self::MIN_PAIRS) {
            return null;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $covariance = 0.0;
        $varX = 0.0;
        $varY = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $covariance += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return null;
        }

        return ['r' => round($covariance / sqrt($varX * $varY), 2), 'days' => $n];
    }
}
