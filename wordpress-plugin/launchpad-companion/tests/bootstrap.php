<?php
/**
 * PHPUnit bootstrap for the WordPress test suite. Runs under wp-env via
 * wp-phpunit (CI), or the classic WP test library when WP_TESTS_DIR is set.
 * Loads the plugin as an mu-plugin so its hooks register before the tests run.
 *
 * @package Launchpad\Companion
 */

// Composer dev deps: yoast/phpunit-polyfills + wp-phpunit (sets WP_PHPUNIT__DIR).
$_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($_autoload)) {
    require_once $_autoload;
}

$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
    $_tests_dir = getenv('WP_PHPUNIT__DIR');
}
if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/launchpad-companion.php';
});

require $_tests_dir . '/includes/bootstrap.php';
