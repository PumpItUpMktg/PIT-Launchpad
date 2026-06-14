<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\ContentStore;

class Test_Post_Template extends WP_UnitTestCase
{
    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'content_id' => '01JPOSTTEMPLATE0000000000A',
            'kind' => 'post',
            'kit' => '',
            'slug' => 'a-news-post',
            'status' => 'published',
            'slot_payload' => ['body' => '<p>Article body.</p>'],
        ], $over);
    }

    public function test_a_post_gets_no_canvas_page_template(): void
    {
        $result = ( new ContentStore() )->upsert($this->payload());

        // elementor_canvas would bypass the Theme Builder single template.
        $this->assertSame('', (string) get_post_meta($result['wp_post_id'], '_wp_page_template', true));
    }

    public function test_a_re_push_clears_a_stale_canvas_left_on_a_post(): void
    {
        $store = new ContentStore();
        $first = $store->upsert($this->payload());

        // Simulate the old behavior having stamped canvas, then re-push.
        update_post_meta($first['wp_post_id'], '_wp_page_template', 'elementor_canvas');
        $second = $store->upsert($this->payload());

        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);
        $this->assertSame('', (string) get_post_meta($second['wp_post_id'], '_wp_page_template', true));
    }

    public function test_a_re_push_clears_a_stale_canvas_left_on_a_page(): void
    {
        // The 196 case: a page carrying a pre-existing elementor_canvas (set before
        // 0.4.7, or by an import) must have it cleared on re-push — idempotent,
        // mirroring the post path. (A page manually edited in WP is instead
        // protected by the locked/locally-edited guard and skipped.)
        $store = new ContentStore();
        $page = $this->payload([
            'content_id' => '01JPOSTTEMPLATE0000000000D',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'slug' => 'stale-canvas-page',
            'slot_payload' => ['hero_problem' => 'No hot water'],
        ]);
        $first = $store->upsert($page);

        update_post_meta($first['wp_post_id'], '_wp_page_template', 'elementor_canvas');
        $second = $store->upsert($page);

        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);
        $this->assertSame('', (string) get_post_meta($second['wp_post_id'], '_wp_page_template', true));
    }

    public function test_a_kit_page_gets_no_canvas_so_its_theme_builder_template_renders(): void
    {
        // Page 196 regression: canvas on a kit page blocks the Theme Builder single
        // template the lp_kit condition renders through.
        $result = ( new ContentStore() )->upsert($this->payload([
            'content_id' => '01JPOSTTEMPLATE0000000000B',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'slug' => 'water-heater-repair',
            'slot_payload' => ['hero_problem' => 'No hot water'],
        ]));

        $this->assertSame('', (string) get_post_meta($result['wp_post_id'], '_wp_page_template', true));
    }

    public function test_an_explicit_lp_templates_mapping_still_wins_for_a_page(): void
    {
        update_option('lp_templates', ['service-page' => 'tpl-service.php']);

        $result = ( new ContentStore() )->upsert($this->payload([
            'content_id' => '01JPOSTTEMPLATE0000000000C',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'slug' => 'mapped-page',
            'slot_payload' => ['hero_problem' => 'x'],
        ]));

        $this->assertSame('tpl-service.php', (string) get_post_meta($result['wp_post_id'], '_wp_page_template', true));

        delete_option('lp_templates');
    }

    public function test_a_native_body_page_gets_the_full_width_template(): void
    {
        // A page carrying a per-page _elementor_data renders its own document, so
        // it gets Elementor Full-Width (no theme .page-header H1, full-width) — not
        // the cleared Theme-Builder path a dynamic-tag kit page takes.
        $result = ( new ContentStore() )->upsert($this->payload([
            'content_id' => '01JPOSTTEMPLATE0000000000E',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'slug' => 'native-body-page',
            'slot_payload' => ['hero_problem' => 'No hot water'],
            'elementor_data' => [[
                'id' => 'aaa1111', 'elType' => 'container', 'settings' => [], 'elements' => [], 'isInner' => false,
            ]],
        ]));

        $this->assertSame('elementor_header_footer', (string) get_post_meta($result['wp_post_id'], '_wp_page_template', true));
    }

    public function test_an_explicit_mapping_wins_over_a_native_body(): void
    {
        update_option('lp_templates', ['service-page' => 'tpl-service.php']);

        $result = ( new ContentStore() )->upsert($this->payload([
            'content_id' => '01JPOSTTEMPLATE0000000000F',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'slug' => 'mapped-native-page',
            'slot_payload' => ['hero_problem' => 'x'],
            'elementor_data' => [[
                'id' => 'bbb2222', 'elType' => 'container', 'settings' => [], 'elements' => [], 'isInner' => false,
            ]],
        ]));

        $this->assertSame('tpl-service.php', (string) get_post_meta($result['wp_post_id'], '_wp_page_template', true), 'An operator mapping must win over the native-body default.');

        delete_option('lp_templates');
    }
}
