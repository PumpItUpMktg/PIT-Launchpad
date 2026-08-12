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
