<?php

use App\Jobs\SyncSiteMetrics;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Providers\WeatherMetricProvider;
use App\Models\ClimateStation;
use App\Models\Location;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Support\CurrentSite;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

afterEach(function () {
    CurrentSite::clear();
});

/** NOAA's daily-summaries answer: one row per reported day, rainfall in inches as a string. */
function noaaDays(array $byDate, string $station = 'USW00014737'): array
{
    $rows = [];
    foreach ($byDate as $date => $inches) {
        $rows[] = ['DATE' => $date, 'STATION' => $station, 'PRCP' => (string) $inches];
    }

    return $rows;
}

/**
 * A site with one geocoded location near Allentown's station, and that station already known — so the
 * normals call (a different dataset) never fires and the fake below is only ever the rainfall one.
 */
function rainySite(float $lat = 40.60, float $lng = -75.47): Site
{
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Allentown', 'lat' => $lat, 'lng' => $lng]);
    ClimateStation::query()->create([
        'station_id' => 'USW00014737',
        'name' => 'ALLENTOWN INTL AP',
        'state' => 'PA',
        'lat' => 40.6508,
        'lng' => -75.4492,
        'summer_dew_point_f' => 63.2,
        'fetched_at' => now(),
    ]);

    return $site;
}

it('writes observed daily rainfall into the spine, keyed on the station', function () {
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaDays([
        '2026-08-01' => '0.00',
        '2026-08-02' => '1.08',
        '2026-08-03' => '0.29',
    ]))]);

    $site = rainySite();
    $result = app(WeatherMetricProvider::class)->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-03'));

    expect($result->status)->toBe('success')->and($result->rowsWritten)->toBe(3);

    $rows = MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('provider', 'noaa')->where('metric_key', 'precipitation_in')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->first()->dimension_type)->toBe('station')
        ->and($rows->first()->dimension_value)->toBe('USW00014737')
        // The station's human name travels with the number so a chart can label its own axis.
        ->and($rows->first()->value_json['station'])->toBe('ALLENTOWN INTL AP');

    $inches = fn (string $date) => (float) MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('provider', 'noaa')->where('period_date', $date)->value('value_numeric');

    // A real zero-rain day is a measurement and is kept.
    expect($inches('2026-08-01'))->toBe(0.0)->and($inches('2026-08-02'))->toBe(1.08);
});

it('leaves an unreported day absent rather than calling it dry', function () {
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaDays([
        '2026-08-01' => '0.40',
        '2026-08-02' => '-9999',   // NOAA's missing-observation marker
    ]))]);

    $site = rainySite();
    $result = app(WeatherMetricProvider::class)->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-03'));

    // Two days asked for beyond the one real value; only the real one is stored. A station that did not
    // report is not a station that saw no rain.
    expect($result->rowsWritten)->toBe(1)
        ->and(MetricSnapshot::withoutGlobalScopes()->where('provider', 'noaa')->where('period_date', '2026-08-02')->exists())->toBeFalse();
});

it('is idempotent — a re-run corrects in place, never duplicates', function () {
    $site = rainySite();
    $range = CarbonPeriod::create('2026-08-01', '2026-08-01');

    // One fake, two answers in order — a second Http::fake() call APPENDS a stub rather than replacing
    // the first, so the original would keep winning and the re-run would never see the corrected value.
    Http::fake(['*ncei.noaa.gov*' => Http::sequence()
        ->push(noaaDays(['2026-08-01' => '0.10']))
        ->push(noaaDays(['2026-08-01' => '0.35']))]);

    app(WeatherMetricProvider::class)->sync($site, $range);
    app(WeatherMetricProvider::class)->sync($site, $range);

    $rows = MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('provider', 'noaa')->get();
    expect($rows)->toHaveCount(1)->and((float) $rows->first()->value_numeric)->toBe(0.35);
});

it('stores one series per station, not one per location that shares it', function () {
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaDays(['2026-08-01' => '0.75']))]);

    $site = rainySite();
    // A second branch a few miles away — same weather, same station.
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Bethlehem', 'lat' => 40.63, 'lng' => -75.38]);

    $result = app(WeatherMetricProvider::class)->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-01'));

    expect($result->rowsWritten)->toBe(1)
        ->and(MetricSnapshot::withoutGlobalScopes()->where('provider', 'noaa')->count())->toBe(1);
});

it('says nothing at all for a site with no geocoded location', function () {
    Http::fake();
    $site = Site::factory()->create();

    $result = app(WeatherMetricProvider::class)->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-03'));

    expect($result->rowsWritten)->toBe(0)
        ->and(MetricSnapshot::withoutGlobalScopes()->where('provider', 'noaa')->count())->toBe(0);
    Http::assertNothingSent();
});

it('registers noaa in the provider registry and rides its own queue', function () {
    expect(app(MetricProviderRegistry::class)->has('noaa'))->toBeTrue()
        ->and(SyncSiteMetrics::queueFor('noaa'))->toBe('metrics:noaa');

    Queue::fake();
    $site = Site::factory()->create();
    SyncSiteMetrics::dispatch($site->id, 'noaa', '2026-08-01', '2026-08-31');
    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->provider === 'noaa' && $j->queue === 'metrics:noaa');
});
