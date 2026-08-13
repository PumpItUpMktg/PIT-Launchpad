<?php
/**
 * Plugin Name:       PIG Jobs
 * Plugin URI:        https://pumpitupmarketing.com/pig-jobs
 * Description:       Renders completed-job posts pushed from the Job Capture control plane — a self-contained pig_job CPT + shortcode/block for any WordPress site. The standalone counterpart to the Launchpad companion plugin.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Pump It Up Marketing
 * License:           GPL-2.0-or-later
 * Text Domain:       pig-jobs
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

if (! defined('ABSPATH')) {
    exit;
}

define('PIGJOBS_VERSION', '0.2.0');
define('PIGJOBS_FILE', __FILE__);
define('PIGJOBS_DIR', plugin_dir_path(__FILE__));
define('PIGJOBS_URL', plugin_dir_url(__FILE__));

require_once PIGJOBS_DIR . 'includes/autoload.php';

Plugin::instance()->boot();

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
