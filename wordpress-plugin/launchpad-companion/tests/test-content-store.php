<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\ContentStore;
use Launchpad\Companion\Content\EditGuard;
use Launchpad\Companion\Meta;

class Test_Content_Store extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        // Mock the R2 sideload: return a stub local attachment, no network.
        add_filter('lp_pre_import_image', static function ($pre, array $image) {
            return array_merge($image, ['attachment_id' => 4242, 'source_url' => $image['url'], 'url' => 'http://example.org/wp-content/uploads/hero.jpg']);
        }, 10, 2);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'content_id' => '01JCONTENTSERVICE000000000',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'kit_version' => '1',
            'silo_id' => '01JSILOPLUMBING0000000000',
            'slug' => 'water-heater-repair',
            'status' => 'published',
            'locked' => false,
            'slot_payload' => ['hero_heading' => 'Fast Water Heater Repair'],
            'images' => ['hero' => ['url' => 'https://r2.example/hero.jpg', 'alt' => 'A water heater']],
            'seo' => ['title' => 'Water Heater Repair', 'meta_description' => 'Same-day repair.', 'canonical' => 'https://acme.com/water-heater-repair'],
        ], $over);
    }

    public function test_upsert_is_idempotent_on_content_id(): void
    {
        $store = new ContentStore();

        $a = $store->upsert($this->payload());
        $b = $store->upsert($this->payload(['slot_payload' => ['hero_heading' => 'Updated']]));

        $this->assertNotSame('error', $b['status'], 'The re-push must update, not error: ' . ($b['error'] ?? ''));
        $this->assertSame($a['wp_post_id'], $b['wp_post_id'], 'Same content_id must update, not duplicate.');
        $found = get_posts(['post_type' => ['page', 'post'], 'post_status' => 'any', 'meta_key' => Meta::CONTENT_ID, 'meta_value' => '01JCONTENTSERVICE000000000', 'fields' => 'ids']);
        $this->assertCount(1, $found);

        $slots = get_post_meta($a['wp_post_id'], Meta::SLOTS, true);
        $this->assertSame('Updated', $slots['hero_heading']);
    }

    public function test_a_page_carries_the_silo_category(): void
    {
        $result = (new ContentStore())->upsert($this->payload());

        $cats = wp_get_post_categories($result['wp_post_id']);
        $this->assertNotEmpty($cats, 'A kind=page service page must carry the silo category.');
        $this->assertSame('01JSILOPLUMBING0000000000', get_post_meta($result['wp_post_id'], Meta::SILO_ID, true));
    }

    public function test_slot_seo_and_image_meta_are_stored(): void
    {
        $result = (new ContentStore())->upsert($this->payload());
        $id = $result['wp_post_id'];

        $this->assertSame('Fast Water Heater Repair', get_post_meta($id, Meta::SLOTS, true)['hero_heading']);
        $this->assertSame('Water Heater Repair', get_post_meta($id, Meta::SEO, true)['title']);

        $images = get_post_meta($id, Meta::IMAGES, true);
        $this->assertSame(4242, $images['hero']['attachment_id'], 'Image must be sideloaded to a local attachment.');
        $this->assertSame('https://r2.example/hero.jpg', $images['hero']['source_url']);
    }

    public function test_scalar_slots_are_mirrored_to_readable_bindable_meta_keys(): void
    {
        $store = new ContentStore();
        $result = $store->upsert($this->payload(['slot_payload' => [
            'hero_heading' => 'Fast Water Heater Repair',
            'cta_label' => 'Call now',
            'service_features' => ['Endless hot water', 'Lower bills'], // repeater — not mirrored
        ]]));
        $id = $result['wp_post_id'];

        // Each scalar slot is a readable, NON-protected meta key the Theme Builder
        // template can bind via Elementor's Post Custom Field tag.
        $this->assertSame('Fast Water Heater Repair', get_post_meta($id, 'lp_slot_hero_heading', true));
        $this->assertSame('Call now', get_post_meta($id, 'lp_slot_cta_label', true));
        $this->assertFalse(is_protected_meta('lp_slot_hero_heading', 'post'), 'Mirror keys must be visible/bindable (no leading underscore).');

        // Repeaters bind via the lp/* tags off the consolidated blob, not a scalar mirror.
        $this->assertSame('', get_post_meta($id, 'lp_slot_service_features', true));

        // Re-push is authoritative: a dropped slot's mirror is removed (no stale bind).
        $store->upsert($this->payload(['slot_payload' => ['hero_heading' => 'Fast Water Heater Repair']]));
        $this->assertSame('', get_post_meta($id, 'lp_slot_cta_label', true), 'A dropped slot must not leave a stale mirror.');
    }

    public function test_a_locally_edited_page_is_skipped_not_overwritten(): void
    {
        $store = new ContentStore();
        $first = $store->upsert($this->payload());
        $id = $first['wp_post_id'];

        // Simulate a human edit in WP after the push.
        update_post_meta($id, Meta::LOCALLY_EDITED, '1');

        $second = $store->upsert($this->payload(['slot_payload' => ['hero_heading' => 'Engine tried to overwrite']]));

        $this->assertTrue($second['skipped'], 'A locally-edited page must be reported skipped.');
        $this->assertSame($id, $second['wp_post_id']);
        $this->assertSame('Fast Water Heater Repair', get_post_meta($id, Meta::SLOTS, true)['hero_heading'], 'The operator edit must not be clobbered.');
    }

    public function test_a_locked_payload_is_skipped(): void
    {
        $store = new ContentStore();
        $first = $store->upsert($this->payload());

        $second = $store->upsert($this->payload(['locked' => true, 'slot_payload' => ['hero_heading' => 'nope']]));

        $this->assertTrue($second['skipped']);
        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);
    }

    public function test_lazy_category_when_silo_arrives_after_content(): void
    {
        // No /silo push yet — content references an unseen silo_id.
        $result = (new ContentStore())->upsert($this->payload(['silo_id' => '01JUNSEENSILO000000000000']));

        $cats = wp_get_post_categories($result['wp_post_id']);
        $this->assertNotEmpty($cats, 'An unseen silo_id must lazily create a placeholder category, not fail.');
    }

    public function test_a_native_elementor_body_is_written_and_slots_are_retained(): void
    {
        $body = [[
            'id' => 'abc1234', 'elType' => 'container', 'isInner' => false,
            'settings' => ['_css_classes' => 'lp-zone lp-zone--faq'],
            'elements' => [[
                'id' => 'def5678', 'elType' => 'widget', 'widgetType' => 'nested-accordion',
                'settings' => ['items' => [['item_title' => 'Q1', '_id' => 'aaa1111']]],
                'elements' => [], 'isInner' => false,
            ]],
        ]];

        $result = (new ContentStore())->upsert($this->payload(['elementor_data' => $body]));
        $id = $result['wp_post_id'];

        // Native render: stored as Elementor reads it (decodes back to the tree) + builder mode.
        $stored = json_decode((string) get_post_meta($id, '_elementor_data', true), true);
        $this->assertSame($body, $stored);
        $this->assertSame('builder', get_post_meta($id, '_elementor_edit_mode', true));

        // Dual-write: the slot payload is RETAINED (source of truth for SEO/schema + re-gen).
        $this->assertSame('Fast Water Heater Repair', get_post_meta($id, Meta::SLOTS, true)['hero_heading']);
    }

    public function test_no_elementor_data_in_payload_writes_no_native_body(): void
    {
        $result = (new ContentStore())->upsert($this->payload()); // no elementor_data key

        $this->assertSame('', get_post_meta($result['wp_post_id'], '_elementor_data', true));
    }

    public function test_a_locally_edited_page_keeps_its_native_body(): void
    {
        $store = new ContentStore();
        $first = $store->upsert($this->payload(['elementor_data' => [[
            'id' => 'orig111', 'elType' => 'container', 'settings' => [], 'elements' => [], 'isInner' => false,
        ]]]));
        $id = $first['wp_post_id'];

        update_post_meta($id, Meta::LOCALLY_EDITED, '1'); // operator edited the page in Elementor

        $second = $store->upsert($this->payload(['elementor_data' => [[
            'id' => 'new222', 'elType' => 'container', 'settings' => [], 'elements' => [], 'isInner' => false,
        ]]]));

        $this->assertTrue($second['skipped']);
        $stored = json_decode((string) get_post_meta($id, '_elementor_data', true), true);
        $this->assertSame('orig111', $stored[0]['id'], 'A re-push must not clobber the operator-edited native body.');
    }
}
