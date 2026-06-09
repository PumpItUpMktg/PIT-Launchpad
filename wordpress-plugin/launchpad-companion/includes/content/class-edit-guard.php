<?php
/**
 * The locked / locally-edited protocol. A managed post that a human edits
 * directly in WordPress (wp-admin or Elementor) is flagged so the next /content
 * push is honored as a skip — the engine's edits never clobber an operator's.
 *
 * Mechanism: the plugin's own REST writes run inside during_write(), which sets a
 * guard so the resulting save_post does NOT self-flag. Any other save_post on a
 * managed post sets _lp_locally_edited.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;
use WP_Post;

if (! defined('ABSPATH')) {
    exit;
}

final class EditGuard
{
    private static bool $writing = false;

    public function register(): void
    {
        // Late priority so the post is fully saved first. Covers the classic
        // editor and most Elementor saves; the Elementor document hook is a
        // belt-and-suspenders backstop for editor builds that bypass save_post.
        add_action('save_post', [$this, 'on_save'], 999, 2);
        add_action('elementor/document/after_save', [$this, 'on_elementor_save'], 999);
    }

    /**
     * Run a plugin-originated write with the guard set, so the save it triggers
     * is not mistaken for a human edit.
     *
     * @template T
     * @param  callable():T  $fn
     * @return T
     */
    public static function during_write(callable $fn): mixed
    {
        $previous = self::$writing;
        self::$writing = true;

        try {
            return $fn();
        } finally {
            self::$writing = $previous;
        }
    }

    public function on_save(int $post_id, WP_Post $post): void
    {
        if (self::$writing) {
            return; // our own REST write
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status === 'auto-draft') {
            return;
        }

        if (! self::is_managed($post_id)) {
            return;
        }

        update_post_meta($post_id, Meta::LOCALLY_EDITED, '1');
    }

    public function on_elementor_save(mixed $document): void
    {
        if (self::$writing || ! is_object($document) || ! method_exists($document, 'get_main_id')) {
            return;
        }

        $post_id = (int) $document->get_main_id();
        if ($post_id > 0 && self::is_managed($post_id)) {
            update_post_meta($post_id, Meta::LOCALLY_EDITED, '1');
        }
    }

    public static function is_locally_edited(int $post_id): bool
    {
        return get_post_meta($post_id, Meta::LOCALLY_EDITED, true) === '1';
    }

    /** Record the push fingerprint after a plugin write (clears the edited flag). */
    public static function record_push(int $post_id, string $hash): void
    {
        update_post_meta($post_id, Meta::LAST_PUSH, $hash);
        delete_post_meta($post_id, Meta::LOCALLY_EDITED);
    }

    private static function is_managed(int $post_id): bool
    {
        return get_post_meta($post_id, Meta::CONTENT_ID, true) !== '';
    }
}
