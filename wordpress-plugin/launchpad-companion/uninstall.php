<?php
/**
 * Uninstall cleanup: remove plugin options and the service role. Managed posts
 * are intentionally left intact.
 *
 * @package Launchpad\Companion
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/autoload.php';

foreach (
    [
        \Launchpad\Companion\Meta::OPTION_SILOS,
        \Launchpad\Companion\Meta::OPTION_TEMPLATES,
        \Launchpad\Companion\Meta::OPTION_REDIRECTS,
    ] as $option
) {
    delete_option($option);
}

\Launchpad\Companion\ServiceUser::uninstall();
