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
}
