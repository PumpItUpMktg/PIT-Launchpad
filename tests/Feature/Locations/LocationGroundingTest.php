<?php

use App\Local\Grounding\LocationGrounding;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

function groundableLocation(array $attrs = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => Site::factory()->create()->id,
        'latitude' => 40.15, 'longitude' => -75.40,
        'home_county_geoid' => '42091', // Montgomery County, PA
        'served_towns' => [['name' => 'Trooper', 'state' => 'PA', 'lat' => 40.15, 'lng' => -75.40, 'geocoded' => true]],
        'grounding_cache' => null,
    ], $attrs));
}

it('fires the trade-mapped sources, caches the facts on the record, and never refetches while fresh', function () {
    Http::fake([
        'archive-api.open-meteo.com/*' => Http::response(['monthly' => [
            'time' => ['2024-03', '2024-06', '2024-12'],
            'precipitation_sum' => [5.0, 3.0, 2.5],
            'temperature_2m_mean' => [48.0, 74.0, 30.0],
        ]], 200),
        'api.census.gov/*' => Http::response([
            ['NAME', 'B01003_001E', 'B25003_001E', 'B25035_001E', 'state', 'county'],
            ['Montgomery County, Pennsylvania', '856553', '340000', '1968', '42', '091'],
        ], 200),
        'maps.googleapis.com/*' => Http::response(['results' => []], 200),
    ]);

    $location = groundableLocation();
    $result = app(LocationGrounding::class)->for($location, 'Basement waterproofing & sump pumps');

    expect($result['facts'])->not->toBe([])
        ->and(implode(' ', $result['facts']))->toContain('Montgomery County')->toContain('1968')
        ->and($result['fetched_at'])->not->toBe('');

    $fresh = $location->fresh();
    expect($fresh->grounding_cache['facts'])->toBe($result['facts']);   // cached on the record

    // Fresh cache → NO refetch (any HTTP call here would be a new fake hit).
    Http::fake(fn () => throw new RuntimeException('refetched a fresh cache'));
    $again = app(LocationGrounding::class)->for($fresh, 'Basement waterproofing & sump pumps');
    expect($again['facts'])->toBe($result['facts']);
});

it('an unknown trade falls back to _default (census only) and a failed source is skipped, never blocking', function () {
    Http::fake([
        'api.census.gov/*' => Http::response(null, 500),                // census down
        '*' => Http::response(['error' => 'should not be called'], 500),
    ]);

    $result = app(LocationGrounding::class)->for(groundableLocation(), 'Chimney sweeping');

    expect($result['facts'])->toBe([])                                   // degraded, not blocked
        ->and($result['sources'])->toBe([])
        ->and($result['fetched_at'])->not->toBe('');                     // still stamps the attempt
});

it('force refetches past the cache', function () {
    Http::fake(['api.census.gov/*' => Http::response([
        ['NAME', 'B01003_001E', 'B25003_001E', 'B25035_001E', 'state', 'county'],
        ['Montgomery County, Pennsylvania', '856553', '340000', '1968', '42', '091'],
    ], 200)]);

    $location = groundableLocation(['grounding_cache' => ['facts' => ['stale fact'], 'sources' => [], 'fetched_at' => now()->subDay()->toIso8601String()]]);

    // Fresh cache honored…
    expect(app(LocationGrounding::class)->for($location, 'chimney')['facts'])->toBe(['stale fact']);
    // …force refetches.
    expect(app(LocationGrounding::class)->for($location->fresh(), 'chimney', force: true)['facts'])
        ->not->toBe(['stale fact']);
});
