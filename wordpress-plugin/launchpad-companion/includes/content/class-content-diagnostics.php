<?php
/**
 * Read-only diagnostics for a control-plane page on THIS WordPress: the answer to "I pushed it but the
 * live page is wrong / the URL has a -2/-3 suffix." It reports the actual post state so the control
 * plane can explain the drift instead of guessing:
 *
 *  - the resolved post (id / status / slug / permalink) for a control-plane ULID,
 *  - whether pushes would be SKIPPED (locked or locally-edited) — the #1 cause of "content never
 *    updates AND the slug never gets reclaimed", because {@see ContentStore} bails before writing the
 *    body or fixing the slug when a page is protected,
 *  - slug DRIFT: post_name != the control-plane canonical, and WHICH post is squatting the clean slug
 *    (owned by us, or an unmanaged/human page we won't clobber),
 *  - duplicate posts carrying the same ULID (should be exactly one).
 *
 * Nothing is mutated. @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\EditGuard;
use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class ContentDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public function diagnose(string $content_id, string $expected_slug = ''): array
    {
        $expected = self::last_segment($expected_slug);

        $ids = get_posts([
            'post_type'        => ['page', 'post'],
            'post_status'      => 'any',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => Meta::CONTENT_ID,
            'meta_value'       => $content_id,
            'suppress_filters' => false,
        ]);
        $ids = array_map('intval', $ids);

        if ($ids === []) {
            // Nothing carries our ULID — report whether a stray already squats the target slug.
            return [
                'content_id'      => $content_id,
                'found'           => false,
                'duplicate_count' => 0,
                'expected_slug'   => $expected,
                'slug_holder'     => $expected !== '' ? $this->slug_holder($expected, 0) : null,
            ];
        }

        $post_id   = $ids[0];
        $post_name = (string) get_post_field('post_name', $post_id);
        $locked    = get_post_meta($post_id, Meta::LOCKED, true) === '1';
        $edited    = EditGuard::is_locally_edited($post_id);
        $drifted   = $expected !== '' && $post_name !== $expected;

        return [
            'content_id'      => $content_id,
            'found'           => true,
            'wp_post_id'      => $post_id,
            'status'          => (string) get_post_status($post_id),
            'post_name'       => $post_name,
            'permalink'       => (string) get_permalink($post_id),
            // The two reasons a push is a silent no-op (ContentStore skips BEFORE body + slug reclaim).
            'locked'          => $locked,
            'locally_edited'  => $edited,
            'push_would_skip' => $locked || $edited,
            // Slug drift + who is blocking the clean slug.
            'expected_slug'   => $expected,
            'slug_drifted'    => $drifted,
            'slug_holder'     => $drifted ? $this->slug_holder($expected, $post_id) : null,
            // Should be exactly 1 — more means a stray still carries our ULID.
            'duplicate_count' => count($ids),
        ];
    }

    /**
     * The post (if any) holding a given slug at the site root, and whether WE own it. A launchpad-owned
     * or draft/trashed squatter is one the reclaim CAN rename aside; an unmanaged published page is one
     * we deliberately won't clobber (so it explains a permanent -2/-3 suffix).
     *
     * @return array<string, mixed>|null
     */
    private function slug_holder(string $slug, int $exclude_id): ?array
    {
        if ($slug === '') {
            return null;
        }

        $holders = get_posts([
            'post_type'        => ['page', 'post'],
            'post_status'      => 'any',
            'post_parent'      => 0,
            'name'             => $slug,
            'numberposts'      => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]);

        foreach (array_map('intval', $holders) as $hid) {
            if ($hid === $exclude_id) {
                continue;
            }

            $owned = (string) get_post_meta($hid, Meta::CONTENT_ID, true) !== '';

            return [
                'wp_post_id' => $hid,
                'status'     => (string) get_post_status($hid),
                // Owned/draft/trashed → the reclaim can take the slug back; unmanaged published → it won't.
                'reclaimable' => $owned || in_array(get_post_status($hid), ['draft', 'auto-draft', 'trash'], true),
            ];
        }

        return null;
    }

    private static function last_segment(string $slug): string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return '';
        }
        $parts = explode('/', $slug);

        return sanitize_title((string) end($parts));
    }
}
