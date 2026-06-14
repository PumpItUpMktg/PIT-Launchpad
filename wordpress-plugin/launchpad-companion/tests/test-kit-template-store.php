<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\KitTaxonomy;
use Launchpad\Companion\Content\KitTemplateStore;
use Launchpad\Companion\Meta;

class Test_Kit_Template_Store extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        KitTaxonomy::register();
        register_post_type('elementor_library', ['public' => false, 'show_ui' => true]);
        register_taxonomy('elementor_library_type', 'elementor_library', ['public' => false]);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'kit' => 'service-page',
            'title' => 'Single Page – Service',
            'template' => [
                'version' => '0.4',
                'title' => 'Single Page – Service',
                'type' => 'section',
                'content' => [
                    [
                        'id' => 'abc1234',
                        'elType' => 'widget',
                        'widgetType' => 'heading',
                        'settings' => ['title' => '', '_css_classes' => 'wf-hero_problem'],
                    ],
                ],
            ],
        ], $over);
    }

    public function test_it_creates_an_elementor_library_single_template(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload());

        $this->assertTrue($result['created']);
        $this->assertGreaterThan(0, $result['template_id']);
        $this->assertSame('elementor_library', get_post_type($result['template_id']));
        $this->assertSame('single-page', get_post_meta($result['template_id'], '_elementor_template_type', true));
    }

    public function test_it_stores_the_elementor_data_tree(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload());

        $data = get_post_meta($result['template_id'], '_elementor_data', true);
        $this->assertStringContainsString('wf-hero_problem', (string) $data);
        $decoded = json_decode((string) $data, true);
        $this->assertIsArray($decoded);
        $this->assertSame('heading', $decoded[0]['widgetType']);
    }

    public function test_it_marks_the_template_with_its_kit_for_idempotency(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload());

        $this->assertSame('service-page', get_post_meta($result['template_id'], Meta::KIT_TEMPLATE, true));
    }

    public function test_a_re_push_updates_the_same_template_not_a_duplicate(): void
    {
        $store = new KitTemplateStore();
        $first = $store->install($this->payload());

        $second = $store->install($this->payload([
            'template' => [
                'content' => [[
                    'id' => 'def5678',
                    'elType' => 'widget',
                    'widgetType' => 'heading',
                    'settings' => ['_css_classes' => 'wf-hero_heading'],
                ]],
            ],
        ]));

        $this->assertSame($first['template_id'], $second['template_id']);
        $this->assertFalse($second['created']);

        $all = get_posts([
            'post_type' => 'elementor_library',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => Meta::KIT_TEMPLATE,
            'meta_value' => 'service-page',
        ]);
        $this->assertCount(1, $all); // one template per kit
        $this->assertStringContainsString('wf-hero_heading', (string) get_post_meta($second['template_id'], '_elementor_data', true));
    }

    public function test_it_ensures_the_lp_kit_term_the_condition_targets(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload());

        $term = get_term_by('slug', 'service-page', KitTaxonomy::TAXONOMY);
        $this->assertInstanceOf(\WP_Term::class, $term);
        $this->assertSame($term->term_id, $result['condition']['term_id']);
    }

    public function test_without_pro_the_condition_is_advisory_only(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload());

        // wp-env free has no Elementor Pro: the import succeeds, the condition is
        // recorded as advisory meta but reported as not auto-set.
        $this->assertFalse($result['pro']);
        $this->assertFalse($result['condition_set']);

        $advisory = get_post_meta($result['template_id'], Meta::KIT_TEMPLATE_CONDITION, true);
        $this->assertIsArray($advisory);
        $this->assertSame('lp_kit', $advisory['taxonomy']);
        $this->assertSame('singular', $advisory['location']);
    }

    public function test_an_empty_payload_is_rejected_without_creating_a_template(): void
    {
        $result = ( new KitTemplateStore() )->install(['kit' => '', 'template' => []]);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $result['template_id']);
    }

    public function test_the_template_type_is_overridable(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload(['template_type' => 'single-post']));

        $this->assertSame('single-post', get_post_meta($result['template_id'], '_elementor_template_type', true));
    }

    public function test_it_clears_a_conflicting_kit_condition_from_another_template(): void
    {
        $store = new KitTemplateStore();
        $first = $store->install($this->payload());
        $term_id = (int) $first['condition']['term_id'];

        // A rogue (e.g. hand-made) template claims the SAME kit condition, plus an
        // unrelated one that must survive.
        $rogue = wp_insert_post([
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'post_title' => 'Hand-made',
        ]);
        update_post_meta($rogue, '_elementor_conditions', ["include/singular/in_lp_kit/{$term_id}", 'include/general']);

        // Re-push: the canonical template must become the sole owner of the kit.
        $second = $store->install($this->payload());

        $this->assertContains($rogue, $second['conditions_cleared']);
        $this->assertSame(['include/general'], get_post_meta($rogue, '_elementor_conditions', true)); // only kit rule stripped
    }

    public function test_it_leaves_a_different_kits_condition_untouched(): void
    {
        $store = new KitTemplateStore();
        $first = $store->install($this->payload());

        // A template for a DIFFERENT kit term — must not be touched.
        $other = wp_insert_post([
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'post_title' => 'Other kit',
        ]);
        update_post_meta($other, '_elementor_conditions', ['include/singular/in_lp_kit/999999']);

        $second = $store->install($this->payload());

        $this->assertNotContains($other, $second['conditions_cleared'] ?? []);
        $this->assertSame(['include/singular/in_lp_kit/999999'], get_post_meta($other, '_elementor_conditions', true));
    }

    public function test_a_clean_install_clears_no_conditions(): void
    {
        $result = ( new KitTemplateStore() )->install($this->payload());

        $this->assertSame([], $result['conditions_cleared']);
    }
}
