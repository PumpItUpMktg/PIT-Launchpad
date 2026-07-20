<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\SiteProfileStore;
use Launchpad\Companion\Render\WeatherAlert;

class Test_Weather_Alert extends WP_UnitTestCase
{
    /** Open-Meteo daily shape: parallel time[] + precipitation_sum[] (inches). */
    private function daily(array $rows): array
    {
        return [
            'time' => array_column($rows, 0),
            'precipitation_sum' => array_column($rows, 1),
        ];
    }

    public function test_flags_the_first_day_over_the_threshold(): void
    {
        $daily = $this->daily([
            [gmdate('Y-m-d', strtotime('+1 day')), 0.2],
            [gmdate('Y-m-d', strtotime('+2 day')), 1.4],  // the first heavy day
            [gmdate('Y-m-d', strtotime('+3 day')), 2.1],
        ]);

        $event = WeatherAlert::wettest_day($daily, 1.0);

        $this->assertIsArray($event);
        $this->assertSame(gmdate('Y-m-d', strtotime('+2 day')), $event['date']);
        $this->assertSame(1.4, $event['inches']);
        $this->assertNotSame('', $event['label']);
    }

    public function test_returns_null_when_no_day_clears_the_threshold(): void
    {
        $daily = $this->daily([
            [gmdate('Y-m-d', strtotime('+1 day')), 0.1],
            [gmdate('Y-m-d', strtotime('+2 day')), 0.4],
        ]);

        $this->assertNull(WeatherAlert::wettest_day($daily, 1.0));
    }

    public function test_null_on_empty_or_malformed_forecast(): void
    {
        $this->assertNull(WeatherAlert::wettest_day([], 1.0));
        $this->assertNull(WeatherAlert::wettest_day(['time' => ['2026-07-24'], 'precipitation_sum' => [null]], 1.0));
    }

    public function test_profile_store_sanitizes_and_gates_the_alert_config(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Dry Basements',
            'alert' => ['enabled' => true, 'lat' => 40.12, 'lng' => -75.34, 'noun' => 'sump pump', 'cta_label' => 'Book a check', 'cta_url' => 'https://x.example/contact'],
        ]);
        $alert = SiteProfileStore::get()['alert'];

        $this->assertTrue($alert['enabled']);
        $this->assertSame(40.12, $alert['lat']);
        $this->assertSame('sump pump', $alert['noun']);
        $this->assertSame('https://x.example/contact', $alert['cta_url']);
    }

    public function test_alert_is_forced_off_without_valid_coordinates(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Dry Basements',
            'alert' => ['enabled' => true, 'lat' => 'nonsense', 'lng' => -75.34],
        ]);

        $this->assertFalse(SiteProfileStore::get()['alert']['enabled']);
    }

    public function test_no_banner_renders_when_the_alert_is_disabled(): void
    {
        ( new SiteProfileStore() )->save(['brand_name' => 'X', 'alert' => ['enabled' => false]]);

        ob_start();
        ( new WeatherAlert() )->render();
        $out = ob_get_clean();

        $this->assertSame('', $out);
    }
}
