<?php

use Launchpad\Companion\Render\AirQuality;

/**
 * The companion plugin's air-quality card. Only the PURE evaluator is exercised here — the shortcode
 * itself needs WordPress — which is the reason the banding lives in a static with no WP dependency.
 */
beforeEach(function (): void {
    if (! defined('ABSPATH')) {
        define('ABSPATH', __DIR__);   // the plugin files guard on this and exit without it
    }
    require_once base_path('wordpress-plugin/launchpad-companion/includes/render/class-air-quality.php');
});

it('reads Open-Meteo\'s current block into the EPA\'s own bands', function (int $aqi, string $band) {
    $reading = AirQuality::evaluate([
        'time' => '2026-09-17T19:00', 'us_aqi' => $aqi, 'pm2_5' => 9.24, 'ozone' => 93.0,
    ]);

    expect($reading['aqi'])->toBe($aqi)
        ->and($reading['band'])->toBe($band)
        ->and($reading['pm25'])->toBe(9.2)                    // rounded for display, not invented precision
        ->and($reading['taken'])->toBe('2026-09-17T19:00');   // the hour is part of the fact
})->with([
    [42, 'Good'],
    [86, 'Moderate'],                                // the live reading for Doylestown when this was built
    [120, 'Unhealthy for sensitive groups'],
    [180, 'Unhealthy'],
    [250, 'Very unhealthy'],
    [400, 'Hazardous'],
]);

it('renders no card at all when there is no index, and omits a pollutant it did not get', function () {
    expect(AirQuality::evaluate([]))->toBeNull()
        ->and(AirQuality::evaluate(['us_aqi' => null]))->toBeNull();

    // Open-Meteo's pollen fields are Europe-only and come back null in the US — nothing here reads them,
    // and a missing pollutant simply drops off the card rather than rendering as zero.
    $reading = AirQuality::evaluate([
        'time' => '2026-09-17T19:00', 'us_aqi' => 86, 'grass_pollen' => null, 'ragweed_pollen' => null,
    ]);
    expect($reading['pm25'])->toBeNull()->and($reading['ozone'])->toBeNull()->and($reading['band'])->toBe('Moderate');
});
