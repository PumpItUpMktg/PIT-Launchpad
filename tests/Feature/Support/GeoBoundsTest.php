<?php

use App\Support\GeoBounds;

it('accepts real US service-area coordinates (CONUS, AK, HI, PR)', function () {
    expect(GeoBounds::isWithinServiceArea(40.7244717, -74.1725407))->toBeTrue()  // Newark, NJ
        ->and(GeoBounds::isWithinServiceArea(39.4041888, -76.2911611))->toBeTrue() // Abingdon, MD
        ->and(GeoBounds::isWithinServiceArea(30.26, -97.74))->toBeTrue()           // Austin, TX
        ->and(GeoBounds::isWithinServiceArea(61.2181, -149.9003))->toBeTrue()      // Anchorage, AK
        ->and(GeoBounds::isWithinServiceArea(21.3069, -157.8583))->toBeTrue()      // Honolulu, HI
        ->and(GeoBounds::isWithinServiceArea(18.2208, -66.5901))->toBeTrue();      // Puerto Rico
});

it('rejects the South-Pacific corruption and other out-of-area but valid-Earth coordinates', function () {
    expect(GeoBounds::isWithinServiceArea(-29.6238960, -175.4491260))->toBeFalse() // the prod grid centre
        ->and(GeoBounds::isWithinServiceArea(51.5074, -0.1278))->toBeFalse()       // London (lng east of box)
        ->and(GeoBounds::isWithinServiceArea(-33.8688, 151.2093))->toBeFalse();    // Sydney
});

it('treats a null component as never valid (a missing coordinate is not a location)', function () {
    expect(GeoBounds::isWithinServiceArea(null, -74.0))->toBeFalse()
        ->and(GeoBounds::isWithinServiceArea(40.7, null))->toBeFalse()
        ->and(GeoBounds::isWithinServiceArea(null, null))->toBeFalse();
});

it('honors config-tuned bounds', function () {
    config()->set('launchpad.geo.service_area_bounds', ['lat_min' => 0.0, 'lat_max' => 0.0, 'lng_min' => 0.0, 'lng_max' => 0.0]);

    expect(GeoBounds::isWithinServiceArea(0.0, 0.0))->toBeTrue()
        ->and(GeoBounds::isWithinServiceArea(40.7, -74.0))->toBeFalse();
});
