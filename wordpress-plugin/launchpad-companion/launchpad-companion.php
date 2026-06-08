<?php
/**
 * Plugin Name:       Launchpad Companion
 * Description:       Receiver on each client site for the Launchpad control plane. Accepts contract pushes, stores content, renders it through brand-neutral Elementor templates, and emits native SEO. No SEO plugin, no ACF, no media-library import.
 * Version:           0.1.0
 * Requires PHP:      8.0
 * Requires at least: 6.3
 * Author:            Pump It Up Marketing
 * License:           GPL-2.0-or-later
 * Text Domain:       launchpad-companion
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

if (! defined('ABSPATH')) {
    exit;
}

define('LPC_VERSION', '0.1.0');
define('LPC_FILE', __FILE__);
define('LPC_DIR', plugin_dir_path(__FILE__));
define('LPC_URL', plugin_dir_url(__FILE__));

require_once LPC_DIR . 'includes/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
