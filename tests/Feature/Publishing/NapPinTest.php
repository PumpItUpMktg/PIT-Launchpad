<?php

use App\Integrations\Census\Geocoder;
use App\Integrations\Census\GeocodeResult;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Blocks\NapPin;

function bindGeocoder(?GeocodeResult $result, int &$calls): void
{
    app()->instance(Geocoder::class, new class($result, $calls) implements Geocoder
    {
        public function __construct(private ?GeocodeResult $result, private int &$calls) {}

        public function geocode(string $address): ?GeocodeResult
        {
            $this->calls++;

            return $this->result;
        }
    });
}

it('geocodes a storefront address ONCE and caches the pin on the Location', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'is_storefront' => true,
        'address' => '12 Main Street, Newark, NJ', 'latitude' => null, 'longitude' => null, 'geocoded_at' => null,
    ]);
    $calls = 0;
    bindGeocoder(new GeocodeResult(40.7357, -74.1724, '12 Main St'), $calls);

    $pin = app(NapPin::class)->for($site->id);
    expect($pin['lat'])->toBe(40.7357)
        ->and($pin['lng'])->toBe(-74.1724)
        ->and($pin['label'])->toBe('12 Main Street, Newark, NJ');

    // Cached — a second resolve never re-hits the geocoder.
    app(NapPin::class)->for($site->id);
    expect($calls)->toBe(1);

    $location = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->first();
    expect((float) $location->latitude)->toBe(40.7357)
        ->and($location->geocoded_at)->not->toBeNull();
});

it('a mobile-only business never gets a pin — its base address is not mapped', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'is_storefront' => false, 'address' => '12 Main Street, Newark, NJ',
    ]);
    $calls = 0;
    bindGeocoder(new GeocodeResult(40.0, -74.0, 'x'), $calls);

    expect(app(NapPin::class)->for($site->id))->toBeNull()
        ->and($calls)->toBe(0); // never even geocoded
});

it('an ungeocodable address fails open (no pin) and is not retried on every push', function () {
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id, 'is_storefront' => true,
        'address' => 'nowhere', 'latitude' => null, 'longitude' => null, 'geocoded_at' => null,
    ]);
    $calls = 0;
    bindGeocoder(null, $calls); // geocoder finds nothing

    expect(app(NapPin::class)->for($site->id))->toBeNull();
    app(NapPin::class)->for($site->id);
    expect($calls)->toBe(1); // geocoded_at stamps the miss — no retry loop
});
