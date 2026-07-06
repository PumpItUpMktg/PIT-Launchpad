<?php
/**
 * Plugin Name:       Launchpad Companion
 * Description:       Receiver on each client site for the Launchpad control plane. Accepts the launchpad/v1 contract pushes (silo/content/redirects), stores content idempotently by control-plane ULID, sideloads images, stores pages as core Gutenberg block markup (post_content) for a block theme, emits native SEO, and honors the locked / locally-edited protocol. No page builder, no SEO plugin, no ACF.
 * Version:           0.9.3
 * Requires PHP:      8.0
 * Requires at least: 6.6
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

define('LPC_VERSION', '0.9.3');
define('LPC_FILE', __FILE__);
define('LPC_DIR', plugin_dir_path(__FILE__));
define('LPC_URL', plugin_dir_url(__FILE__));

require_once LPC_DIR . 'includes/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();

    // Pages are core Gutenberg blocks rendered by the active block theme — no page
    // builder required. Surface a non-fatal admin notice when a BLOCK theme is not
    // active, since that (not Elementor) is now the rendering dependency.
    add_action('after_setup_theme', static function (): void {
        if (! wp_is_block_theme()) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('Launchpad Companion: the active theme is not a block theme. The contract endpoints still receive and store content, but managed pages render best on the Launchpad block theme (a Twenty Twenty-Five child).', 'launchpad-companion');
                echo '</p></div>';
            });
        }
    });
});
