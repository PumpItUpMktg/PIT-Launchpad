<?php

use App\Jobs\SyncSiteMetrics;
use App\Metrics\Providers\WeatherMetricProvider;
use App\Models\GscUrlDaily;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function rain(Site $site, string $date, float $inches): void
{
    MetricSnapshot::withoutGlobalScopes()->create([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'provider' => WeatherMetricProvider::PROVIDER,
        'metric_key' => WeatherMetricProvider::METRIC,
        'dimension_type' => 'station',
        'dimension_value' => 'USW00014737',
        'period_grain' => 'day',
        'period_date' => $date,
        'value_numeric' => $inches,
        'value_json' => ['station' => 'ALLENTOWN INTL AP'],
        'captured_at' => now(),
    ]);
}

it('queues a rainfall sync per site on the noaa queue', function () {
    Queue::fake();
    Site::factory()->count(2)->create();

    $this->artisan('launchpad:sync-weather', ['--days' => 30])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, 2);
    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->provider === 'noaa');
});

it('queues only the named site', function () {
    Queue::fake();
    $site = Site::factory()->create();
    Site::factory()->create();

    $this->artisan('launchpad:sync-weather', ['--site' => $site->id])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, 1);
    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->siteId === $site->id);
});

it('says how to fill the spine when a site has no stored rainfall', function () {
    $site = Site::factory()->create();

    $this->artisan('launchpad:report-rainfall', ['--site' => $site->id])
        ->expectsOutputToContain('No rainfall stored for this site')
        ->assertSuccessful();
});

it('reports the rain, the lag, and the correlation — as a correlation', function () {
    $site = Site::factory()->create(['brand_name' => 'Sump Pump Gurus']);

    // Thirty days where clicks track the rain, so there is a real coefficient to print.
    for ($i = 0; $i < 30; $i++) {
        $date = Carbon::today()->subDays(35 - $i);
        $inches = (float) ($i % 4) / 2;
        rain($site, $date->toDateString(), $inches);
        GscUrlDaily::withoutGlobalScopes()->create([
            'id' => (string) Str::ulid(),
            'site_id' => $site->id,
            'grain_hash' => Str::random(32),
            'date' => $date->toDateString(),
            'url' => 'https://example.test/sump-pump-repair',
            'impressions' => 500,
            'clicks' => (int) round(40 + $inches * 20),
            'position' => 8.0,
        ]);
    }

    $this->artisan('launchpad:report-rainfall', ['--site' => $site->id, '--days' => 60])
        ->expectsOutputToContain('ALLENTOWN INTL AP')
        ->expectsOutputToContain('publishes daily summaries about three days behind')
        ->expectsOutputToContain('r = +1.00')
        ->expectsOutputToContain('This is a correlation, not a cause.')
        ->assertSuccessful();
});

it('refuses to guess which site without one named', function () {
    $this->artisan('launchpad:report-rainfall')->assertFailed();
});
