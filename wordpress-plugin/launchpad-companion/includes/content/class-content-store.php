<?php
/**
 * Upserts a page or post from a contract /content payload (idempotent on the
 * control-plane content_id ULID): stores the consolidated slot blob + SEO under
 * single meta keys (no ACF), sideloads images, assigns the silo category (pages
 * AND posts), routes the kit template, and honors the locked / locally-edited
 * protocol — returning {skipped:true} rather than clobbering an operator's edits.
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
     * @return array{content_id: string, wp_post_id: int, status: string, skipped: bool, error?: string}
     */
    public function upsert(array $payload): array
    {
        $content_id = (string) ($payload['content_id'] ?? '');
        $kind = ($payload['kind'] ?? 'page') === 'post' ? 'post' : 'page';

        $existing_id = $this->find($content_id);

        // Locked protocol: never overwrite an operator-locked or locally-edited
        // page. Echo the post id and report the skip; the engine records it.
        if ($existing_id > 0 && $this->is_protected($existing_id, $payload)) {
            return [
                'content_id' => $content_id,
                'wp_post_id' => $existing_id,
                'status' => (string) get_post_status($existing_id),
                'skipped' => true,
            ];
        }

        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $status = ($payload['status'] ?? '') === 'published' ? 'publish' : 'draft';

        // The Gutenberg body: when the push carries core-block markup, it IS the WP
        // post_content (the block theme renders it — no page builder). Absent → '' and
        // the legacy Elementor-body path takes over below (backward compatible).
        $block_content = is_string($payload['post_content'] ?? null) ? trim((string) $payload['post_content']) : '';

        $postarr = [
            'post_type' => $kind,
            'post_status' => $status,
            'post_title' => (string) ($seo['title'] ?? $payload['title'] ?? 'Untitled'),
            'post_content' => $block_content,
        ];
        if (! empty($payload['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $payload['slug']);
        }
        if ($existing_id > 0) {
            $postarr['ID'] = $existing_id;
        }

        // Run the write under the edit guard so the resulting save_post is not
        // mistaken for a human edit. We pass $wp_error=true, so a failure comes
        // back as a WP_Error object — it must be inspected, never (int)-cast
        // (casting a WP_Error to int is a fatal in PHP 8 and would mask the real
        // reason behind the graceful-error branch below).
        $result = EditGuard::during_write(static function () use ($postarr, $existing_id) {
            return $existing_id > 0
                ? wp_update_post(wp_slash($postarr), true)
                : wp_insert_post(wp_slash($postarr), true);
        });

        if (is_wp_error($result)) {
            return [
                'content_id' => $content_id,
                'wp_post_id' => $existing_id,
                'status' => 'error',
                'skipped' => false,
                'error' => $result->get_error_code().': '.$result->get_error_message(),
            ];
        }

        $post_id = (int) $result;
        if ($post_id <= 0) {
            return ['content_id' => $content_id, 'wp_post_id' => $existing_id, 'status' => 'error', 'skipped' => false];
        }

        $images = is_array($payload['images'] ?? null)
            ? ImageImporter::import_all($payload['images'], $post_id)
            : [];

        $this->store_meta($post_id, $content_id, $kind, $payload, $seo, $images);
        $this->store_body($post_id, $payload, $block_content);
        $this->apply_featured_image($post_id, $payload, $images);
        TemplateRouter::assign($post_id, (string) ($payload['kit'] ?? ''), (string) ($payload['page_type'] ?? ''));
        $this->assign_category($post_id, (string) ($payload['silo_id'] ?? ''));

        EditGuard::record_push($post_id, $this->fingerprint($payload));

        return [
            'content_id' => $content_id,
            'wp_post_id' => $post_id,
            'status' => $status,
            'skipped' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function is_protected(int $post_id, array $payload): bool
    {
        return ! empty($payload['locked'])
            || get_post_meta($post_id, Meta::LOCKED, true) === '1'
            || EditGuard::is_locally_edited($post_id);
    }

    /**
     * Dispatch the page body. A Gutenberg push (core-block markup) already landed as the WP
     * post_content at insert/update time, so here we only STRIP any stale Elementor body — a page
     * re-pushed from the old Elementor path to the new block path must render its blocks, not a
     * leftover `_elementor_data` document. A push with no block content falls back to the legacy
     * Elementor-body write, unchanged.
     *
     * @param  array<string, mixed>  $payload
     */
    private function store_body(int $post_id, array $payload, string $block_content): void
    {
        if ($block_content !== '') {
            delete_post_meta($post_id, '_elementor_data');
            delete_post_meta($post_id, '_elementor_edit_mode');
            delete_post_meta($post_id, '_elementor_version');

            return;
        }

        $this->store_elementor_body($post_id, $payload);
    }

    /**
     * Write the per-page NATIVE Elementor document (the Tier-1 native-widget body)
     * when the push carries one, stored exactly as Elementor reads it back —
     * wp_slash(wp_json_encode(...)) + builder edit-mode + version. It lives ALONGSIDE
     * the slot payload (_lp_slots), which stays the source of truth for SEO/schema
     * (e.g. FAQPage) and re-gen. Reaching here means the page was not locked or
     * locally-edited (upsert guards that first), so a native edit is never clobbered.
     * Absent `elementor_data` → no-op (backward compatible with the dynamic-template
     * render path).
     *
     * @param  array<string, mixed>  $payload
     */
    private function store_elementor_body(int $post_id, array $payload): void
    {
        $data = $payload['elementor_data'] ?? null;
        if (! is_array($data) || $data === []) {
            return;
        }

        update_post_meta($post_id, '_elementor_data', wp_slash((string) wp_json_encode($data)));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : LPC_VERSION);

        // The page template (full-width chrome for a native body) is owned by
        // TemplateRouter::assign(), the single authority on _wp_page_template — it
        // runs right after this and reads the native body we just wrote.

        // Clear Elementor's generated CSS so the new body renders without a manual
        // regenerate. No-op when Elementor is absent.
        if (class_exists('\Elementor\Plugin')) {
            $elementor = \Elementor\Plugin::$instance;
            if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
                $elementor->files_manager->clear_cache();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $seo
     * @param  array<string, array<string, mixed>>  $images
     */
    private function store_meta(int $post_id, string $content_id, string $kind, array $payload, array $seo, array $images): void
    {
        $slots = is_array($payload['slot_payload'] ?? null) ? $payload['slot_payload'] : [];

        update_post_meta($post_id, Meta::CONTENT_ID, $content_id);
        update_post_meta($post_id, Meta::SLOTS, $slots);
        $this->mirror_slots($post_id, $slots);
        update_post_meta($post_id, Meta::SEO, $seo);
        update_post_meta($post_id, Meta::IMAGES, $images);
        update_post_meta($post_id, Meta::KIND, $kind);
        update_post_meta($post_id, Meta::PAGE_TYPE, (string) ($payload['page_type'] ?? ''));
        update_post_meta($post_id, Meta::KIT, (string) ($payload['kit'] ?? ''));
        update_post_meta($post_id, Meta::KIT_VERSION, (string) ($payload['kit_version'] ?? ''));
        update_post_meta($post_id, Meta::SILO_ID, (string) ($payload['silo_id'] ?? ''));
        update_post_meta($post_id, Meta::LOCKED, ! empty($payload['locked']) ? '1' : '0');

        // §7b template mapping: stamp the kit marker (the Theme Builder display-
        // condition target) on kit PAGES, and record the operator-resolved
        // template id. Rendering is driven by the operator's condition against the
        // lp_kit term — explicit mapping over the kit's elementor_template_ref.
        $kit = (string) ($payload['kit'] ?? '');
        if ($kind === 'page' && $kit !== '') {
            KitTaxonomy::assign($post_id, $kit);
        }

        $template_id = $payload['template_id'] ?? null;
        if (is_int($template_id) || (is_string($template_id) && ctype_digit($template_id))) {
            update_post_meta($post_id, Meta::TEMPLATE_ID, (int) $template_id);
        } else {
            delete_post_meta($post_id, Meta::TEMPLATE_ID);
        }

        $this->store_kit_definition($payload);
    }

    /**
     * Store the kit's contract definition (key/label/content_type/cardinality/
     * required per slot) per "{kit}@{version}", so the Slots & Shortcodes reference
     * reflects the contract across every push, not just one page's slot data.
     *
     * @param  array<string, mixed>  $payload
     */
    private function store_kit_definition(array $payload): void
    {
        $kit = (string) ($payload['kit'] ?? '');
        $slots = $payload['kit_definition'] ?? null;

        if ($kit === '' || ! is_array($slots) || $slots === []) {
            return;
        }

        $version = (string) ($payload['kit_version'] ?? '');
        $key = $kit . '@' . $version;

        $all = get_option(Meta::OPTION_KIT_DEFINITIONS, []);
        if (! is_array($all)) {
            $all = [];
        }

        $all[$key] = ['kit' => $kit, 'version' => $version, 'slots' => $slots];
        update_option(Meta::OPTION_KIT_DEFINITIONS, $all);
    }

    /**
     * Mirror each SCALAR slot to an individual readable meta key (`lp_slot_{key}`)
     * so a Theme Builder template can bind it via Elementor's native Post Custom
     * Field tag and it shows in the Custom Fields box. Re-push is authoritative:
     * mirrored keys absent from the new payload are removed, so a dropped slot
     * doesn't leave stale bound content. Repeaters/objects are not mirrored — they
     * bind through the lp/* dynamic tags off the consolidated blob.
     *
     * @param  array<string, mixed>  $slots
     */
    private function mirror_slots(int $post_id, array $slots): void
    {
        foreach (array_keys(get_post_meta($post_id)) as $key) {
            if (is_string($key) && str_starts_with($key, Meta::SLOT_PREFIX)) {
                delete_post_meta($post_id, $key);
            }
        }

        foreach ($slots as $slot_key => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $suffix = sanitize_key((string) $slot_key);
            if ($suffix === '') {
                continue;
            }

            update_post_meta($post_id, Meta::SLOT_PREFIX.$suffix, (string) $value);
        }
    }

    /**
     * Set the post's featured image (post thumbnail) from the engine-designated
     * `featured_image` (the og/hero image URL). It is normally already sideloaded
     * in the images map — match it by source/local URL and reuse that attachment;
     * otherwise sideload it directly. This is what makes a featured image land on
     * posts (which have no kit hero slot) and gives the theme/og a real image.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $images
     */
    private function apply_featured_image(int $post_id, array $payload, array $images): void
    {
        $featured = isset($payload['featured_image']) ? (string) $payload['featured_image'] : '';
        if ($featured === '') {
            return;
        }

        $attachment_id = 0;
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }
            $source = (string) ($image['source_url'] ?? '');
            $local = (string) ($image['url'] ?? '');
            if (($source !== '' && $source === $featured) || ($local !== '' && $local === $featured)) {
                $attachment_id = (int) ($image['attachment_id'] ?? 0);
                break;
            }
        }

        if ($attachment_id === 0) {
            $imported = ImageImporter::import(['url' => $featured], $post_id);
            $attachment_id = (int) ($imported['attachment_id'] ?? 0);
        }

        if ($attachment_id > 0) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    /**
     * Link the silo category — for BOTH pages and posts (service/location pages
     * are kind=page and must carry it). Lazily creates a placeholder category if
     * the silo_id hasn't been pushed via /silo yet; a later /silo find-or-updates it.
     */
    private function assign_category(int $post_id, string $silo_id): void
    {
        if ($silo_id === '') {
            return;
        }

        $term_id = SiloStore::term_for_or_create($silo_id);
        if ($term_id > 0) {
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return md5((string) wp_json_encode([
            $payload['slot_payload'] ?? [],
            $payload['seo'] ?? [],
            $payload['images'] ?? [],
            $payload['slug'] ?? '',
        ]));
    }
}
