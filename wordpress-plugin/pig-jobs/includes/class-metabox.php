<?php
/**
 * The page-tagging metabox: on any page/post the site owner can set the city + service that page is about,
 * so a bare `[pig_jobs]` on that page auto-renders the matching jobs. A standalone site has no
 * Location/Service records to infer from, and slug/title auto-detection misfires — so this is explicit, by
 * design.
 *
 * @package PIG\Jobs
 */

namespace PIG\Jobs;

use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class Metabox
{
    public const PAGE_CITY = '_pig_page_city';

    public const PAGE_SERVICE = '_pig_page_service';

    private const NONCE = 'pig_jobs_page_tag';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add']);
        add_action('save_post', [$this, 'save'], 10, 2);
    }

    public function add(): void
    {
        foreach (['page', 'post'] as $type) {
            add_meta_box('pig-jobs-page-tag', __('Job Capture — jobs on this page', 'pig-jobs'), [$this, 'render'], $type, 'side');
        }
    }

    public function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE, self::NONCE);
        $city = (string) get_post_meta($post->ID, self::PAGE_CITY, true);
        $service = (string) get_post_meta($post->ID, self::PAGE_SERVICE, true);

        echo '<p><label for="pig_page_city"><strong>' . esc_html__('City slug', 'pig-jobs') . '</strong></label>'
            . '<input type="text" id="pig_page_city" name="pig_page_city" value="' . esc_attr($city) . '" class="widefat" placeholder="e.g. bedminster-nj"></p>';
        echo '<p><label for="pig_page_service"><strong>' . esc_html__('Service slug', 'pig-jobs') . '</strong></label>'
            . '<input type="text" id="pig_page_service" name="pig_page_service" value="' . esc_attr($service) . '" class="widefat" placeholder="e.g. sump-pump-repair"></p>';
        echo '<p class="description">' . esc_html__('A [pig_jobs] shortcode on this page with no city/service will show jobs matching these.', 'pig-jobs') . '</p>';
    }

    public function save(int $post_id, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! isset($_POST[self::NONCE]) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }
        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $this->save_field($post_id, self::PAGE_CITY, 'pig_page_city');
        $this->save_field($post_id, self::PAGE_SERVICE, 'pig_page_service');
    }

    private function save_field(int $post_id, string $meta_key, string $field): void
    {
        $value = isset($_POST[$field]) ? sanitize_title(wp_unslash((string) $_POST[$field])) : '';
        if ($value === '') {
            delete_post_meta($post_id, $meta_key);
        } else {
            update_post_meta($post_id, $meta_key, $value);
        }
    }
}
