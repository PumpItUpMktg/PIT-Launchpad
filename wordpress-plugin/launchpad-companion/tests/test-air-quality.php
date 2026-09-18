<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\SiteProfileStore;
use Launchpad\Companion\Render\AirQuality;

class Test_Air_Quality extends WP_UnitTestCase
{
    public function test_profile_store_keeps_the_air_config_and_gates_it_on_coordinates(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Duct Works',
            'air' => ['enabled' => true, 'lat' => 40.3101, 'lng' => -75.1299],
        ]);
        $air = SiteProfileStore::get()['air'];

        $this->assertTrue($air['enabled']);
        $this->assertSame(40.3101, $air['lat']);

        // Garbage coordinates force it off rather than pointing a fetch at nonsense.
        ( new SiteProfileStore() )->save(['brand_name' => 'X', 'air' => ['enabled' => true, 'lat' => 'nope', 'lng' => -75.1]]);
        $this->assertFalse(SiteProfileStore::get()['air']['enabled']);
    }

    public function test_renders_nothing_for_a_tenant_that_has_not_opted_in(): void
    {
        ( new SiteProfileStore() )->save(['brand_name' => 'X', 'air' => ['enabled' => false, 'lat' => 40.31, 'lng' => -75.13]]);

        $this->assertSame('', ( new AirQuality() )->shortcode([]));
    }

    public function test_bands_a_reading_without_touching_the_network(): void
    {
        $reading = AirQuality::evaluate(['time' => '2026-09-17T19:00', 'us_aqi' => 86, 'pm2_5' => 9.24]);

        $this->assertSame(86, $reading['aqi']);
        $this->assertSame('Moderate', $reading['band']);
        $this->assertSame(9.2, $reading['pm25']);
        $this->assertNull($reading['ozone']);
    }
}
