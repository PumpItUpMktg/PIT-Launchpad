<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Render\Assets;

class Test_Assets extends WP_UnitTestCase
{
    public function test_baseline_stylesheet_enqueues_on_the_front_end(): void
    {
        do_action('wp_enqueue_scripts');

        $this->assertTrue(wp_style_is(Assets::HANDLE, 'enqueued'));
    }

    public function test_stylesheet_is_versioned_and_points_at_the_css_file(): void
    {
        ( new Assets() )->enqueue();

        $styles = wp_styles();
        $this->assertArrayHasKey(Assets::HANDLE, $styles->registered);

        $style = $styles->registered[Assets::HANDLE];
        $this->assertSame(LPC_VERSION, $style->ver);
        $this->assertStringEndsWith('assets/launchpad.css', $style->src);
    }

    public function test_css_file_ships_in_the_plugin(): void
    {
        $this->assertFileExists(LPC_DIR . 'assets/launchpad.css');
    }
}
