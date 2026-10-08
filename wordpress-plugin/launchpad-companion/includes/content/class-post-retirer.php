<?php
/**
 * Retires an UNMANAGED post by its path — the legacy-twin consolidation's remove step.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A legacy post (one the control plane never pushed, so it carries no Launchpad ULID) can only be
 * named by its path. Two refusals keep this from ever being the wrong lever:
 *   - a Launchpad-owned post is refused (that is /content/delete, keyed on the ULID);
 *   - a path the redirect map does not cover is refused — retiring it would 404 a URL Google holds.
 * The post is TRASHED, not force-deleted, so a mistake is recoverable from the WordPress trash.
 */
final class PostRetirer
{
    /**
     * @return array<string, mixed>
     */
    public function retire(string $path): array
    {
        $path = RedirectStore::normalize(trim($path));
        if ($path === '' || $path === '/') {
            return ['path' => $path, 'retired' => false, 'error' => 'path required'];
        }

        $map = get_option(Meta::OPTION_REDIRECTS, []);
        if (! is_array($map) || ! isset($map[$path])) {
            return ['path' => $path, 'retired' => false, 'error' => 'no redirect covers this path — retiring it would 404 a live URL'];
        }

        $post_id = self::post_at($path);
        if ($post_id <= 0) {
            // Nothing served there (already trashed, or never a post) — idempotent success.
            return ['path' => $path, 'retired' => true, 'already_absent' => true];
        }

        if (! in_array(get_post_type($post_id), ['page', 'post'], true)) {
            return ['path' => $path, 'wp_post_id' => $post_id, 'retired' => false, 'error' => 'not a page or post'];
        }
        if ((string) get_post_meta($post_id, Meta::CONTENT_ID, true) !== '') {
            return ['path' => $path, 'wp_post_id' => $post_id, 'retired' => false, 'error' => 'Launchpad-owned post — use /content/delete'];
        }

        $trashed = EditGuard::during_write(static fn () => wp_trash_post($post_id));
        if (! $trashed) {
            return ['path' => $path, 'wp_post_id' => $post_id, 'retired' => false, 'error' => 'wp_trash_post failed'];
        }

        return ['path' => $path, 'wp_post_id' => $post_id, 'retired' => true];
    }

    /**
     * The published page or post served at a path. Pretty permalinks resolve through the rewrite rules
     * (url_to_postid); a site on plain permalinks — or a path the rules cannot parse — falls back to the
     * slug hierarchy, which is the same thing WordPress does to serve the request.
     */
    private static function post_at(string $path): int
    {
        $post_id = (int) url_to_postid(home_url($path . '/'));
        if ($post_id > 0) {
            return $post_id;
        }

        $post = get_page_by_path(trim($path, '/'), OBJECT, ['page', 'post']);

        return $post instanceof \WP_Post && $post->post_status !== 'trash' ? (int) $post->ID : 0;
    }
}
