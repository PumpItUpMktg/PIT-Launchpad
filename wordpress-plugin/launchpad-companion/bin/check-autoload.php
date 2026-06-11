<?php
/**
 * Verifies the autoloader's class -> file mapping for every plugin class,
 * without bootstrapping WordPress or Elementor. Mirrors includes/autoload.php.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$map = static function (string $class) use ($root): string {
    $relative = substr($class, strlen('Launchpad\\Companion\\'));
    $parts = explode('\\', $relative);
    $name = array_pop($parts);
    $dirs = array_map(
        static fn (string $s): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $s)),
        $parts
    );
    $file = 'class-' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name)) . '.php';

    return $root . '/includes/' . ($dirs ? implode('/', $dirs) . '/' : '') . $file;
};

$classes = [
    'Launchpad\\Companion\\Plugin',
    'Launchpad\\Companion\\Meta',
    'Launchpad\\Companion\\ServiceUser',
    'Launchpad\\Companion\\Sitemap',
    'Launchpad\\Companion\\Redirects',
    'Launchpad\\Companion\\Rest\\Routes',
    'Launchpad\\Companion\\Rest\\Status',
    'Launchpad\\Companion\\Content\\ContentStore',
    'Launchpad\\Companion\\Content\\SiloStore',
    'Launchpad\\Companion\\Content\\RedirectStore',
    'Launchpad\\Companion\\Render\\Payload',
    'Launchpad\\Companion\\Render\\TemplateRouter',
    'Launchpad\\Companion\\Render\\TagManager',
    'Launchpad\\Companion\\Render\\SlotRenderer',
    'Launchpad\\Companion\\Render\\Shortcodes',
    'Launchpad\\Companion\\Render\\ShortcodeReference',
    'Launchpad\\Companion\\Admin\\SlotsScreen',
    'Launchpad\\Companion\\Render\\DynamicTags\\TextTag',
    'Launchpad\\Companion\\Render\\DynamicTags\\ImageTag',
    'Launchpad\\Companion\\Render\\DynamicTags\\CtaTag',
    'Launchpad\\Companion\\Render\\DynamicTags\\MapTag',
    'Launchpad\\Companion\\Render\\DynamicTags\\RepeaterTag',
    'Launchpad\\Companion\\Render\\DynamicTags\\SlotControl',
    'Launchpad\\Companion\\Seo\\Head',
    'Launchpad\\Companion\\Seo\\Schema',
    'Launchpad\\Companion\\Seo\\Breadcrumbs',
];

$failed = 0;
foreach ($classes as $class) {
    $path = $map($class);
    if (! is_readable($path)) {
        echo "MISSING: {$class} -> {$path}\n";
        $failed++;
    }
}

if ($failed === 0) {
    echo 'Autoloader resolves all ' . count($classes) . " classes.\n";
    exit(0);
}

exit(1);
