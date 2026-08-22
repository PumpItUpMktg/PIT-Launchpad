<?php

use App\Analytics\Gsc\Grain;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Providers\GscMetricProvider;
use App\Metrics\UrlNormalizer;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Support\CurrentSite;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    CurrentSite::clear();
});

function gscDaily(Site $site, string $date, string $url, int $impressions, int $clicks = 0, ?float $position = null): void
{
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(),
        'site_id' => $site->id,
        'grain_hash' => Grain::hash([$site->id, $date, $url]),
        'date' => $date,
        'url' => $url,
        'impressions' => $impressions,
        'clicks' => $clicks,
        'ctr' => 0,
        'position' => $position,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, MetricSnapshot> keyed "metric|dimType|dimValue|date" for terse assertions. */
function spineFor(Site $site): array
{
    $out = [];
    foreach (MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->get() as $s) {
        $out[$s->metric_key.'|'.$s->dimension_type.'|'.$s->dimension_value.'|'.$s->period_date->toDateString()] = $s;
    }

    return $out;
}

it('rolls gsc_url_daily up into site- and page-level daily snapshots', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $d = '2026-08-10';

    // Two URLs normalize to the same page (/a); a third page (/b).
    gscDaily($site, $d, 'https://apex.example/a/', 100, 10, 8.0);
    gscDaily($site, $d, 'https://apex.example/a', 50, 5, 4.0);
    gscDaily($site, $d, 'https://apex.example/b/', 20, 1, 15.0);

    $rows = (new GscMetricProvider)->sync($site, CarbonPeriod::create($d, $d));
    expect($rows->status)->toBe('success');

    $spine = spineFor($site);

    // Site-level totals for the day.
    expect((int) $spine["impressions|site||$d"]->value_numeric)->toBe(170)
        ->and((int) $spine["clicks|site||$d"]->value_numeric)->toBe(16);

    // Page /a: /a/ and /a merged; impression-weighted position = (8*100 + 4*50) / 150 = 6.6667.
    expect((int) $spine["impressions|page|/a|$d"]->value_numeric)->toBe(150)
        ->and((int) $spine["clicks|page|/a|$d"]->value_numeric)->toBe(15)
        ->and(round((float) $spine["position|page|/a|$d"]->value_numeric, 4))->toBe(6.6667);

    // Page /b.
    expect((int) $spine["impressions|page|/b|$d"]->value_numeric)->toBe(20)
        ->and((int) $spine["position|page|/b|$d"]->value_numeric)->toBe(15);
});

it('is idempotent — a re-run updates in place, never double-counts', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $d = '2026-08-10';
    gscDaily($site, $d, 'https://apex.example/a', 100, 10, 5.0);

    $provider = new GscMetricProvider;
    $provider->sync($site, CarbonPeriod::create($d, $d));
    $provider->sync($site, CarbonPeriod::create($d, $d)); // repeat

    expect(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('metric_key', 'impressions')->where('dimension_type', 'site')->count())->toBe(1)
        ->and((int) spineFor($site)["impressions|site||$d"]->value_numeric)->toBe(100);
});

it('only rolls up rows inside the requested range', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    gscDaily($site, '2026-08-10', 'https://apex.example/a', 100, 10, 5.0);
    gscDaily($site, '2026-07-01', 'https://apex.example/a', 999, 99, 5.0); // outside

    (new GscMetricProvider)->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-31'));

    $siteImpr = MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)
        ->where('metric_key', 'impressions')->where('dimension_type', 'site')->sum('value_numeric');
    expect((int) $siteImpr)->toBe(100); // the July row is excluded
});

it('is a clean no-op when the source store is empty', function () {
    $site = Site::factory()->create();

    $result = (new GscMetricProvider)->sync($site, CarbonPeriod::create('2026-08-01', '2026-08-31'));

    expect($result->status)->toBe('success')
        ->and($result->rowsWritten)->toBe(0)
        ->and(MetricSnapshot::withoutGlobalScopes()->count())->toBe(0);
});

it('registers gsc in the provider registry', function () {
    $registry = app(MetricProviderRegistry::class);

    expect($registry->has('gsc'))->toBeTrue()
        ->and($registry->get('gsc'))->toBeInstanceOf(GscMetricProvider::class);
});

it('verify-gsc reports a match when the spine mirrors the source', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example', 'brand_name' => 'Apex']);
    $d = now()->subDays(2)->toDateString();
    gscDaily($site, $d, 'https://apex.example/a', 100, 10, 5.0);
    (new GscMetricProvider)->sync($site, CarbonPeriod::create(now()->subDays(28)->toDateString(), now()->toDateString()));

    $this->artisan('sandhog:verify-gsc', ['site' => $site->id])
        ->expectsOutputToContain('Spine matches the GSC source')
        ->assertSuccessful();
});

it('verify-gsc fails when the spine is missing rows', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);
    $d = now()->subDays(2)->toDateString();
    gscDaily($site, $d, 'https://apex.example/a', 100, 10, 5.0); // source has data, spine not synced

    $this->artisan('sandhog:verify-gsc', ['site' => $site->id])->assertFailed();
});

it('normalizes paths and urls to one canonical key', function () {
    expect(UrlNormalizer::path('https://apex.example/Foo/'))->toBe('/foo')
        ->and(UrlNormalizer::path('/Foo'))->toBe('/foo')
        ->and(UrlNormalizer::path('https://apex.example/'))->toBe('/')
        ->and(UrlNormalizer::path('https://apex.example'))->toBe('/')
        ->and(UrlNormalizer::url('HTTPS://Apex.Example/Foo/'))->toBe('https://apex.example/foo')
        ->and(UrlNormalizer::url('https://apex.example/'))->toBe('https://apex.example')
        ->and(UrlNormalizer::url('https://apex.example/a?b=1#frag'))->toBe('https://apex.example/a');
});
