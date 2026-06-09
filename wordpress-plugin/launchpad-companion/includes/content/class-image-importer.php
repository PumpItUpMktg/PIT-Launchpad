<?php
/**
 * Sideloads engine-rendered images (already on R2) into the media library,
 * idempotently. Keyed on the R2 source URL (stored as attachment meta), so an
 * unchanged image is reused on re-push rather than re-fetched. Sets alt / title /
 * caption from the contract. On a fetch failure it falls back to the R2 URL
 * (hotlink) rather than blocking the publish.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class ImageImporter
{
    /**
     * Import every image in the slot=>object map; returns the map with each
     * object resolved to its local attachment (id + url) where the sideload
     * succeeded.
     *
     * @param  array<string, array<string, mixed>>  $images
     * @return array<string, array<string, mixed>>
     */
    public static function import_all(array $images, int $post_id): array
    {
        $out = [];
        foreach ($images as $slot => $image) {
            $out[$slot] = is_array($image) ? self::import($image, $post_id) : $image;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    public static function import(array $image, int $post_id): array
    {
        $url = isset($image['url']) ? (string) $image['url'] : '';
        if ($url === '') {
            return $image;
        }

        // Test/extension seam: short-circuit the network sideload. Returning an
        // array here (e.g. a stubbed attachment) bypasses download_url entirely.
        $pre = apply_filters('lp_pre_import_image', null, $image, $post_id);
        if (is_array($pre)) {
            return $pre;
        }

        $attachment_id = self::find_existing($url);

        if ($attachment_id === 0) {
            $attachment_id = self::sideload($url, $post_id);
            if ($attachment_id === 0) {
                return $image; // fetch failed → keep the R2 URL (hotlink fallback)
            }
            update_post_meta($attachment_id, Meta::IMAGE_SOURCE, $url);
        }

        self::apply_metadata($attachment_id, $image);

        $local = wp_get_attachment_url($attachment_id);

        return array_merge($image, [
            'attachment_id' => $attachment_id,
            'source_url' => $url,
            'url' => is_string($local) && $local !== '' ? $local : $url,
        ]);
    }

    private static function find_existing(string $url): int
    {
        $existing = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => Meta::IMAGE_SOURCE,
            'meta_value' => $url,
            'suppress_filters' => false,
        ]);

        return $existing ? (int) $existing[0] : 0;
    }

    private static function sideload(string $url, int $post_id): int
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $name = self::filename($url);
        $attachment_id = media_handle_sideload(
            ['name' => $name, 'tmp_name' => $tmp],
            $post_id
        );

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            return 0;
        }

        return (int) $attachment_id;
    }

    /**
     * @param  array<string, mixed>  $image
     */
    private static function apply_metadata(int $attachment_id, array $image): void
    {
        if (! empty($image['alt'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $image['alt']));
        }

        $update = ['ID' => $attachment_id];
        if (! empty($image['title'])) {
            $update['post_title'] = sanitize_text_field((string) $image['title']);
        }
        if (! empty($image['caption'])) {
            $update['post_excerpt'] = sanitize_text_field((string) $image['caption']);
        }
        if (count($update) > 1) {
            EditGuard::during_write(static fn () => wp_update_post($update));
        }
    }

    private static function filename(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base = $path !== '' ? basename($path) : '';

        return $base !== '' ? sanitize_file_name($base) : 'launchpad-' . md5($url) . '.jpg';
    }
}
