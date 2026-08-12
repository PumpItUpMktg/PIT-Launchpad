<?php
/**
 * Upserts a Job Capture post (the `pig_job` CPT) from the control plane's `/job` blob, keyed on the
 * control-plane ULID (§9/§10). Idempotent: one post per ULID (a re-push updates rather than duplicates,
 * and collapses any stray duplicate). Only public-safe fields arrive — the true address / exact point are
 * never sent, so nothing sensitive is stored. Images are sideloaded into the media library (the client's
 * copy of record) and deduped by their control-plane key so an unchanged re-push re-uploads nothing; the
 * primary becomes the featured image.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class JobStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{job_id: string, wp_post_id: int, status: string, skipped: bool, error?: string}
     */
    public function upsert(array $payload): array
    {
        $job_id = (string) ($payload['job_id'] ?? '');
        if ($job_id === '') {
            return ['job_id' => '', 'wp_post_id' => 0, 'status' => 'error', 'skipped' => false, 'error' => 'job_id required'];
        }

        $existing = $this->find($job_id);
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $status = ($payload['status'] ?? '') === 'publish' ? 'publish' : 'draft';

        $postarr = [
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => $status,
            'post_title' => (string) ($seo['title'] ?? $payload['title'] ?? 'Completed Job'),
            'post_content' => $this->body($payload),
            'post_excerpt' => (string) ($seo['meta_description'] ?? ''),
        ];
        if (! empty($payload['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $payload['slug']);
        }
        if ($existing > 0) {
            $postarr['ID'] = $existing;
        }

        $result = $existing > 0
            ? wp_update_post(wp_slash($postarr), true)
            : wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($result)) {
            return [
                'job_id' => $job_id,
                'wp_post_id' => $existing,
                'status' => 'error',
                'skipped' => false,
                'error' => $result->get_error_code() . ': ' . $result->get_error_message(),
            ];
        }

        $post_id = (int) $result;
        if ($post_id <= 0) {
            return ['job_id' => $job_id, 'wp_post_id' => $existing, 'status' => 'error', 'skipped' => false];
        }

        $this->collapse_duplicates($job_id, $post_id);

        update_post_meta($post_id, Meta::JOB_ID, $job_id);
        update_post_meta($post_id, Meta::JOB_DATA, $this->public_blob($payload));

        $location = is_array($payload['location'] ?? null) ? $payload['location'] : [];
        $city = trim((string) ($location['city'] ?? ''));
        JobCpt::assign($post_id, JobCpt::TAX_CITY, $city !== '' ? [['name' => $city]] : []);
        JobCpt::assign($post_id, JobCpt::TAX_SERVICE, is_array($payload['job_types'] ?? null) ? $payload['job_types'] : []);

        $this->sync_images($post_id, is_array($payload['images'] ?? null) ? $payload['images'] : []);

        return ['job_id' => $job_id, 'wp_post_id' => $post_id, 'status' => (string) get_post_status($post_id), 'skipped' => false];
    }

    /**
     * Force-delete the post for a ULID (the unapprove pull-down). Idempotent — an already-absent post is
     * a success.
     *
     * @return array{job_id: string, deleted: bool, error?: string}
     */
    public function delete(string $job_id): array
    {
        if ($job_id === '') {
            return ['job_id' => '', 'deleted' => false, 'error' => 'job_id required'];
        }

        $post_id = $this->find($job_id);
        if ($post_id <= 0) {
            return ['job_id' => $job_id, 'deleted' => true]; // already gone
        }

        $deleted = wp_delete_post($post_id, true);

        return ['job_id' => $job_id, 'deleted' => (bool) $deleted];
    }

    /** The post id for a ULID, or 0. */
    private function find(string $job_id): int
    {
        if ($job_id === '') {
            return 0;
        }

        $ids = get_posts([
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => Meta::JOB_ID,
            'meta_value' => $job_id,
            'suppress_filters' => false,
        ]);

        return $ids ? (int) $ids[0] : 0;
    }

    /** Keep exactly one post per ULID — force-delete any stray duplicate carrying this job_id. */
    private function collapse_duplicates(string $job_id, int $keep_id): void
    {
        $ids = get_posts([
            'post_type' => JobCpt::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => Meta::JOB_ID,
            'meta_value' => $job_id,
            'suppress_filters' => false,
        ]);

        foreach ($ids as $id) {
            if ((int) $id !== $keep_id) {
                wp_delete_post((int) $id, true);
            }
        }
    }

    /** The Gutenberg body — the enhanced description as core paragraph blocks (block-theme native). */
    private function body(array $payload): string
    {
        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/', $description) ?: [$description];

        return implode("\n\n", array_map(
            static fn (string $p): string => "<!-- wp:paragraph -->\n<p>" . esc_html(trim($p)) . "</p>\n<!-- /wp:paragraph -->",
            array_filter(array_map('trim', $paragraphs), static fn (string $p): bool => $p !== '')
        ));
    }

    /**
     * The public job blob stored for rendering — client display name, location (jittered coords only),
     * job types, and images. Never the true address / exact point (the control plane never sends them).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function public_blob(array $payload): array
    {
        return [
            'client_name' => (string) ($payload['client_name'] ?? ''),
            'location' => is_array($payload['location'] ?? null) ? $payload['location'] : [],
            'job_types' => is_array($payload['job_types'] ?? null) ? $payload['job_types'] : [],
            'images' => is_array($payload['images'] ?? null) ? $payload['images'] : [],
        ];
    }

    /**
     * Sideload the job's images into the media library (deduped by control-plane key) and set the primary
     * as the featured image. An unchanged re-push re-uploads nothing.
     *
     * @param  array<int, array<string, mixed>>  $images
     */
    private function sync_images(int $post_id, array $images): void
    {
        if ($images === []) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $map = get_post_meta($post_id, Meta::JOB_MEDIA, true);
        $map = is_array($map) ? $map : [];
        $primary_id = 0;

        foreach ($images as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $key = (string) ($image['key'] ?? $url);
            $attachment_id = isset($map[$key]) ? (int) $map[$key] : 0;

            if ($attachment_id <= 0 || ! get_post($attachment_id)) {
                $sideloaded = media_sideload_image($url, $post_id, (string) ($image['alt'] ?? ''), 'id');
                if (is_wp_error($sideloaded)) {
                    continue;
                }
                $attachment_id = (int) $sideloaded;
                $map[$key] = $attachment_id;
            }

            if (! empty($image['alt'])) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $image['alt']));
            }
            if (! empty($image['primary'])) {
                $primary_id = $attachment_id;
            }
        }

        update_post_meta($post_id, Meta::JOB_MEDIA, $map);

        if ($primary_id > 0) {
            set_post_thumbnail($post_id, $primary_id);
        } elseif ($map !== []) {
            set_post_thumbnail($post_id, (int) reset($map));
        }
    }
}
