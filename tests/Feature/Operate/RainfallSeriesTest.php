<?php

use App\Metrics\Providers\WeatherMetricProvider;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Operate\RainfallSeries;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function storeRain(Site $site, string $date, float $inches, string $station = 'USW00014737', string $name = 'ALLENTOWN INTL AP'): void
{
    MetricSnapshot::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'provider' => WeatherMetricProvider::PROVIDER,
        'metric_key' => WeatherMetricProvider::METRIC,
        'dimension_type' => 'station',
        'dimension_value' => $station,
        'period_grain' => 'day',
        'period_date' => $date,
        'value_numeric' => $inches,
        'value_json' => ['station' => $name],
        'captured_at' => now(),
    ]);
}

it('averages a multi-station territory rather than adding it up', function () {
    $site = Site::factory()->create();
    storeRain($site, '2026-08-01', 1.00);
    storeRain($site, '2026-08-01', 2.00, 'USW00013739', 'PHILADELPHIA INTL AP');

    // The territory saw about an inch and a half. Summing would claim three inches fell.
    expect(app(RainfallSeries::class)->daily($site, Carbon::parse('2026-07-01'))['2026-08-01'])->toBe(1.5);
});

it('names the stations it is speaking for', function () {
    $site = Site::factory()->create();
    storeRain($site, '2026-08-01', 0.2);
    storeRain($site, '2026-08-02', 0.4, 'USW00013739', 'PHILADELPHIA INTL AP');

    expect(app(RainfallSeries::class)->stations($site))
        ->toBe(['USW00013739' => 'PHILADELPHIA INTL AP', 'USW00014737' => 'ALLENTOWN INTL AP']);
});

it('reports the last day NOAA actually reported', function () {
    $site = Site::factory()->create();
    storeRain($site, '2026-09-15', 0.0);
    storeRain($site, '2026-09-17', 0.4);

    expect(app(RainfallSeries::class)->through($site)?->toDateString())->toBe('2026-09-17')
        ->and(app(RainfallSeries::class)->through(Site::factory()->create()))->toBeNull();
});

it('stays quiet about another tenant\'s weather', function () {
    $mine = Site::factory()->create();
    $theirs = Site::factory()->create();
    storeRain($theirs, '2026-08-01', 3.0);

    expect(app(RainfallSeries::class)->daily($mine, Carbon::parse('2026-07-01')))->toBe([]);
});

it('correlates two series that move together', function () {
    $rain = $clicks = [];
    for ($i = 0; $i < 20; $i++) {
        $date = Carbon::parse('2026-08-01')->addDays($i)->toDateString();
        $rain[$date] = (float) ($i % 5);
        $clicks[$date] = 100.0 + ($i % 5) * 10;   // clicks track the rain exactly
    }

    expect(app(RainfallSeries::class)->correlate($rain, $clicks))->toBe(['r' => 1.0, 'days' => 20]);
});

it('refuses a coefficient on too few overlapping days', function () {
    $rain = $clicks = [];
    for ($i = 0; $i < 10; $i++) {
        $date = Carbon::parse('2026-08-01')->addDays($i)->toDateString();
        $rain[$date] = (float) $i;
        $clicks[$date] = (float) $i;
    }

    // Ten days of agreement is noise dressed up as a number — better to say nothing.
    expect(app(RainfallSeries::class)->correlate($rain, $clicks))->toBeNull();
});

it('refuses a coefficient when a series never varies', function () {
    $rain = $clicks = [];
    for ($i = 0; $i < 20; $i++) {
        $date = Carbon::parse('2026-08-01')->addDays($i)->toDateString();
        $rain[$date] = 0.0;           // a dry fortnight
        $clicks[$date] = (float) $i;
    }

    // Not 0.0 — "nothing to compare" must not print as "no relationship".
    expect(app(RainfallSeries::class)->correlate($rain, $clicks))->toBeNull();
});
