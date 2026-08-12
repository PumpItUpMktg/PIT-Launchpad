<?php
/**
 * Minimal autoloader for the PIG\Jobs namespace — maps class names to WordPress-style class-*.php files
 * under includes/.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

if (! defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    // ClassName -> class-class-name.php
    $file = 'class-' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $relative)) . '.php';
    $path = PIGJOBS_DIR . 'includes/' . $file;

    if (is_readable($path)) {
        require_once $path;
    }
});
