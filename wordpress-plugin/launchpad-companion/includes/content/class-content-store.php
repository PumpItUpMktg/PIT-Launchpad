<?php
/**
 * Upserts a page or post from a contract /content payload: stores the
 * consolidated slot blob under one meta key, assigns the page-type Elementor
 * template and silo category, and honors the locked flag.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\TemplateRouter;

if (! defined('ABSPATH')) {
    exit;
}

final class ContentStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{content_id: string, wp_post_id: int, status: string, skipped: bool}
     */
    public function upsert(array $payload): array
    {
        $content_id = (string) ($payload['content_id'] ?? '');
        $kind = ($payload['kind'] ?? 'page') === 'post' ? 'post' : 'page';
        $forced = ! empty($payload['force']);

        $existing_id = $this->find($content_id);

        if ($existing_id > 0 && $this->is_locked($existing_id) && ! $forced) {
            return [
                'content_id' => $content_id,
                'wp_post_id' => $existing_id,
                'status' => (string) get_post_status($existing_id),
                'skipped' => true,
            ];
        }

        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $status = ($payload['status'] ?? '') === 'published' ? 'publish' : 'draft';

        $postarr = [
            'post_type' => $kind,
            'post_status' => $status,
            'post_title' => (string) ($seo['title'] ?? $payload['title'] ?? 'Untitled'),
            'post_content' => '',
        ];

        if (! empty($payload['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $payload['slug']);
        }

        if ($existing_id > 0) {
            $postarr['ID'] = $existing_id;
            $post_id = (int) wp_update_post(wp_slash($postarr), true);
        } else {
            $post_id = (int) wp_insert_post(wp_slash($postarr), true);
        }

        $this->store_meta($post_id, $content_id, $kind, $payload, $seo);

        TemplateRouter::assign($post_id, (string) ($payload['page_type'] ?? ''));

        $this->assign_category($post_id, $kind, (string) ($payload['silo_id'] ?? ''));

        return [
            'content_id' => $content_id,
            'wp_post_id' => $post_id,
            'status' => $status,
            'skipped' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $seo
     */
    private function store_meta(int $post_id, string $content_id, string $kind, array $payload, array $seo): void
    {
        update_post_meta($post_id, Meta::CONTENT_ID, $content_id);
        update_post_meta($post_id, Meta::SLOTS, is_array($payload['slot_payload'] ?? null) ? $payload['slot_payload'] : []);
        update_post_meta($post_id, Meta::SEO, $seo);
        update_post_meta($post_id, Meta::IMAGES, is_array($payload['images'] ?? null) ? $payload['images'] : []);
        update_post_meta($post_id, Meta::KIND, $kind);
        update_post_meta($post_id, Meta::PAGE_TYPE, (string) ($payload['page_type'] ?? ''));
        update_post_meta($post_id, Meta::KIT, (string) ($payload['kit'] ?? ''));
        update_post_meta($post_id, Meta::KIT_VERSION, (string) ($payload['kit_version'] ?? ''));
        update_post_meta($post_id, Meta::SILO_ID, (string) ($payload['silo_id'] ?? ''));
        update_post_meta($post_id, Meta::LOCKED, ! empty($payload['locked']) ? '1' : '0');
    }

    private function assign_category(int $post_id, string $kind, string $silo_id): void
    {
        if ($kind !== 'post' || $silo_id === '') {
            return;
        }

        $term_id = SiloStore::term_for($silo_id);

        if ($term_id !== null) {
            wp_set_post_categories($post_id, [$term_id], false);
        }
    }

    private function find(string $content_id): int
    {
        if ($content_id === '') {
            return 0;
        }

        $posts = get_posts([
            'post_type' => ['page', 'post'],
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => Meta::CONTENT_ID,
            'meta_value' => $content_id,
            'suppress_filters' => false,
        ]);

        return $posts ? (int) $posts[0] : 0;
    }

    private function is_locked(int $post_id): bool
    {
        return get_post_meta($post_id, Meta::LOCKED, true) === '1';
    }
}
