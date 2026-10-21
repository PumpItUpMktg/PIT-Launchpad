<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Rest\Status;

class Test_Status extends WP_UnitTestCase
{
    public function test_payload_reports_core_versions_and_companion(): void
    {
        $p = Status::payload();

        $this->assertSame(get_bloginfo('version'), $p['wp_version']);
        $this->assertSame(PHP_VERSION, $p['php_version']);
        $this->assertSame(LPC_VERSION, $p['companion_version']);
        $this->assertArrayHasKey('elementor_version', $p);
        $this->assertArrayHasKey('elementor_pro_version', $p);
        $this->assertArrayHasKey('name', $p['active_theme']);
        $this->assertArrayHasKey('version', $p['active_theme']);
    }

    public function test_payload_reports_block_theme_and_active_colors(): void
    {
        $p = Status::payload();

        $this->assertArrayHasKey('is_block_theme', $p);
        $this->assertIsBool($p['is_block_theme']);
        $this->assertArrayHasKey('active_colors', $p);
        $this->assertIsArray($p['active_colors']);
    }

    public function test_elementor_pro_version_is_null_when_absent(): void
    {
        if (defined('ELEMENTOR_PRO_VERSION')) {
            $this->markTestSkipped('Elementor Pro is present in this environment.');
        }

        $this->assertNull(Status::payload()['elementor_pro_version']);
    }

    public function test_status_route_is_registered_under_the_contract_namespace(): void
    {
        $routes = rest_get_server()->get_routes('launchpad/v1');

        $this->assertArrayHasKey('/launchpad/v1/status', $routes);
    }

    public function test_payload_reports_the_footer_hq_state_and_a_page_s_stored_state(): void
    {
        ( new \Launchpad\Companion\Content\SiteProfileStore() )->save([
            'brand_name' => 'Sump Pump Gurus',
            'hq' => ['state' => 'NJ', 'url' => 'https://sumppumpgurus.com/contact/'],
        ]);
        $store = new \Launchpad\Companion\Content\ContentStore();
        $r = $store->upsert([
            'content_id' => '01JSTATUSPAGE000000000000', 'kind' => 'page', 'page_type' => 'location', 'kit' => 'location-page',
            'kit_version' => '1', 'slug' => 'trooper-pa', 'status' => 'published', 'locked' => false, 'state' => 'PA',
            'slot_payload' => ['hero_headline' => 'Trooper'], 'images' => [], 'seo' => ['title' => 'Trooper'],
        ]);

        $p = Status::payload('/trooper-pa/');

        $this->assertSame(['state' => 'NJ', 'url' => 'https://sumppumpgurus.com/contact/'], $p['footer_hq']);
        $this->assertTrue($p['page']['found']);
        $this->assertSame($r['wp_post_id'], $p['page']['post_id']);
        $this->assertSame('location', $p['page']['page_type']);
        $this->assertSame('PA', $p['page']['state']);
        $this->assertTrue($p['page']['pushed']);

        $this->assertFalse(Status::payload('/no-such-page/')['page']['found']);
        $this->assertNull(Status::payload()['page']);
    }
}
