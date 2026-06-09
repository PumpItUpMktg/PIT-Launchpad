<?php
/**
 * PHPUnit bootstrap for the WordPress test suite. Run against the WP test library
 * (see bin/install-wp-tests.sh) or `wp-env`. Loads the plugin as an mu-plugin so
 * its hooks register before the tests run.
 *
 * @package Launchpad\Companion
 */

$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/launchpad-companion.php';
});

require $_tests_dir . '/includes/bootstrap.php';
