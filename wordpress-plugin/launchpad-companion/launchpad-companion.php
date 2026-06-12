<?php
/**
 * Plugin Name:       Launchpad Companion
 * Description:       Receiver on each client site for the Launchpad control plane. Accepts the launchpad/v1 contract pushes (silo/content/redirects), stores content idempotently by control-plane ULID, sideloads images, renders through brand-neutral Elementor dynamic tags, emits native SEO, and honors the locked / locally-edited protocol. No SEO plugin, no ACF.
 * Version:           0.4.4
 * Requires PHP:      8.0
 * Requires at least: 6.3
 * Requires Plugins:  elementor
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

define('LPC_VERSION', '0.4.4');
define('LPC_FILE', __FILE__);
define('LPC_DIR', plugin_dir_path(__FILE__));
define('LPC_URL', plugin_dir_url(__FILE__));

require_once LPC_DIR . 'includes/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();

    // Receiving + storage work without Elementor; only on-page rendering needs
    // it. Surface a non-fatal admin notice when Elementor is absent.
    if (! did_action('elementor/loaded') && ! defined('ELEMENTOR_VERSION')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Launchpad Companion: Elementor is not active. The contract endpoints still receive and store content, but pages will not render through Launchpad dynamic tags until Elementor is enabled.', 'launchpad-companion');
            echo '</p></div>';
        });
    }
});
