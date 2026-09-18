<?php

use App\Local\Grounding\HumidityProvider;
use App\Models\ClimateStation;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/** NOAA's hourly-normals answer: one row per hour, the dew point in °F. */
function noaaHours(float $dewPoint, int $hours = 744): array
{
    return array_map(fn (int $i): array => [
        'DATE' => sprintf('07-%02dT%02d:00:00', intdiv($i, 24) + 1, $i % 24),
        'STATION' => 'USW00014737',
        'HLY-DEWP-NORMAL' => (string) $dewPoint,
    ], range(0, $hours - 1));
}

function humidityLocation(float $lat = 40.31, float $lng = -75.13): Location
{
    $site = Site::factory()->create();

    return Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => $lat, 'lng' => $lng]);
}

it('names the dew point and explains the mechanism when the air is stickier than a basement wall', function () {
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaHours(67.0))]);

    $result = app(HumidityProvider::class)->fetch(humidityLocation());

    expect($result['facts'][0])->toMatch('/^Summer dew points here average 67°F \(NOAA 1991–2020 normals, .+ miles away\)\.$/')
        ->and($result['facts'][1])->toContain('gives up its moisture on the walls');

    // The station's own normal is kept, so the next location nearby costs no request.
    $station = ClimateStation::query()->sole();
    expect($station->summer_dew_point_f)->toBe(67.0);

    Http::fake();
    app(HumidityProvider::class)->fetch(humidityLocation(40.33, -75.15));
    Http::assertNothingSent();
});

it('says the opposite where the air is drier than the wall', function () {
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaHours(52.0))]);

    $dry = app(HumidityProvider::class)->fetch(humidityLocation(39.7392, -104.9903));   // Denver

    expect($dry['facts'][1])->toContain('the air dries the space');
});

it('states the number alone when there is no mechanism to explain', function () {
    // (A separate test on purpose: Http::fake() keeps earlier stubs, so a second fake in one test never
    // wins and the assertion would be read against the first response.)
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaHours(59.0))]);

    $middling = app(HumidityProvider::class)->fetch(humidityLocation(41.8781, -87.6298));   // Chicago

    expect($middling['facts'])->toHaveCount(1)
        ->and($middling['facts'][0])->toContain('average 59°F');
});

it('says nothing without coordinates, a usable normal, or a station near enough to mean anything', function () {
    Http::fake();
    $site = Site::factory()->create();
    $blind = Location::factory()->create(['site_id' => $site->id, 'lat' => null, 'lng' => null]);
    expect(app(HumidityProvider::class)->fetch($blind)['facts'])->toBe([]);
    Http::assertNothingSent();

    // NOAA marks a missing normal with -9999; a dew point that low is not weather.
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaHours(-9999.0))]);
    expect(app(HumidityProvider::class)->fetch(humidityLocation())['facts'])->toBe([]);

    // Mid-ocean: the nearest station is not describing this weather, so we do not borrow it.
    ClimateStation::query()->delete();
    Http::fake(['*ncei.noaa.gov*' => Http::response(noaaHours(70.0))]);
    expect(app(HumidityProvider::class)->fetch(humidityLocation(35.0, -45.0))['facts'])->toBe([]);
});

it('carries the whole station network, so no region falls back to a distant station', function () {
    $path = database_path('data/climate/hourly-normals-stations.csv');
    $rows = array_filter(array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));
    array_shift($rows);   // header

    // NOAA publishes 467 stations of hourly normals; an earlier fixed-width parse silently dropped 63 of
    // them (Denver among them) because the elevation column's width varies.
    expect($rows)->toHaveCount(467)
        ->and(collect($rows)->pluck(0))->toContain('USW00003017')      // Denver Intl
        ->and(collect($rows)->firstWhere(0, 'USW00003017')[4])->toBe('DENVER INTL AP');

    // Every row carries usable coordinates and a two-letter state, or the nearest-station maths is junk.
    foreach ($rows as $row) {
        expect(is_numeric($row[1]) && is_numeric($row[2]))->toBeTrue()
            ->and(strlen((string) $row[3]))->toBe(2);
    }
});

/**
 * The trade map is the ship-it-but-not-here decision, pinned so it cannot drift back by accident.
 *
 * Dew point is regional over tens of miles. A waterproofing tenant's offices sit inside one metro, so
 * every hub resolves to the same one or two NOAA stations and prints the same sentence — true, and
 * saying nothing new by the second page. Trades whose tenants can span real climate contrast keep it.
 */
it('fires humidity for the moisture trades and not for waterproofing', function () {
    $map = config('launchpad.grounding.trade_map');

    expect($map['waterproofing'])->not->toContain('humidity')
        ->and($map['dehumidification'])->toContain('humidity')
        ->and($map['mold_testing'])->toContain('humidity')
        ->and($map['hvac'])->toContain('humidity');
});
