<?php

use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\Locations\ServedTowns;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function fakeTownGeocoder(?GeocodeResult $result): void
{
    app()->instance(Geocoder::class, new class($result) implements Geocoder
    {
        public function __construct(private ?GeocodeResult $result) {}

        public function geocode(string $address): ?GeocodeResult
        {
            return $this->result;
        }
    });
}

it('normalizes tag strings to structured towns — new names geocoded, existing keep their coordinates', function () {
    fakeTownGeocoder(new GeocodeResult(40.8259, -74.2090, 'Montclair, NJ'));

    $existing = [['name' => 'Trooper', 'state' => 'PA', 'lat' => 40.15, 'lng' => -75.40, 'geocoded' => true]];
    $towns = app(ServedTowns::class)->normalize(['Trooper, PA', 'Montclair, NJ', 'montclair, nj'], $existing);

    expect($towns)->toHaveCount(2)                             // case-insensitive dedupe
        ->and($towns[0])->toBe(['name' => 'Trooper', 'state' => 'PA', 'lat' => 40.15, 'lng' => -75.40, 'geocoded' => true]) // kept, not refetched
        ->and($towns[1]['name'])->toBe('Montclair')
        ->and($towns[1]['state'])->toBe('NJ')
        ->and($towns[1]['lat'])->toBe(40.8259)
        ->and($towns[1]['geocoded'])->toBeTrue();
});

it('stores an ungeocodable town flagged instead of blocking the save', function () {
    fakeTownGeocoder(null);

    $towns = app(ServedTowns::class)->normalize(['Nowhereville, ZZ']);

    expect($towns[0]['geocoded'])->toBeFalse()
        ->and($towns[0]['lat'])->toBeNull();
});

it('the cannibalization guard names the location that already owns a town — one page per town, per site', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Trooper Office',
        'served_towns' => [['name' => 'Norristown', 'state' => 'PA', 'lat' => 40.1, 'lng' => -75.3, 'geocoded' => true]],
    ]);
    $montclair = Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair Office', 'served_towns' => []]);

    $conflicts = app(ServedTowns::class)->conflicts($site->id, ['Norristown, PA', 'Clifton, NJ'], $montclair->id);
    expect($conflicts)->toBe([['town' => 'Norristown, PA', 'location' => 'Trooper Office']]);

    // A location never conflicts with its own towns (editing in place).
    $trooper = Location::withoutGlobalScope(SiteScope::class)->where('name', 'Trooper Office')->first();
    expect(app(ServedTowns::class)->conflicts($site->id, ['Norristown, PA'], $trooper->id))->toBe([]);

    // Another SITE is free to serve the same town.
    $other = Site::factory()->create();
    expect(app(ServedTowns::class)->conflicts($other->id, ['Norristown, PA']))->toBe([]);
});
