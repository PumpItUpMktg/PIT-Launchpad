<?php
/**
 * Minimal PSR-4-style autoloader for the Launchpad\Companion namespace, mapping
 * class names to WordPress-style class-*.php files under includes/.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

if (! defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $parts = explode('\\', $relative);
    $class_name = array_pop($parts);

    // Sub-namespaces map to lowercase, hyphenated directories.
    $dirs = array_map(
        static fn (string $segment): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $segment)),
        $parts
    );

    // ClassName -> class-class-name.php
    $file_name = 'class-' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name)) . '.php';

    $path = LPC_DIR . 'includes/' . ($dirs ? implode('/', $dirs) . '/' : '') . $file_name;

    if (is_readable($path)) {
        require_once $path;
    }
});
