<?php
/**
 * Uninstall — leaves job content in place (the client keeps their posts + media). Only drops the plugin's
 * page-tag meta. Deliberately conservative: uninstalling the plugin should not destroy published work.
 *
 * @package PIG\Jobs
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_post_meta_by_key('_pig_page_city');
delete_post_meta_by_key('_pig_page_service');

// Drop the service role + its capability (mirrors the companion). Conservative about content, not about
// the sync identity — a reinstall re-provisions it. Require the class directly (the plugin's main file and
// its PIGJOBS_DIR-based autoloader are not loaded during uninstall).
$pigjobs_service_user = __DIR__ . '/includes/class-service-user.php';
if (is_readable($pigjobs_service_user)) {
    require_once $pigjobs_service_user;
    if (class_exists('PIG\\Jobs\\ServiceUser')) {
        PIG\Jobs\ServiceUser::uninstall();
    }
}
delete_option('pigjobs_version');
