<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\ContentStore;
use Launchpad\Companion\Content\KitTaxonomy;
use Launchpad\Companion\Meta;

class Test_Kit_Template extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        KitTaxonomy::register();
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'content_id' => '01JKITTEMPLATE00000000000A',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'kit_version' => '1',
            'slug' => 'water-heater-repair',
            'status' => 'published',
            'slot_payload' => ['hero_heading' => 'Fast Repair'],
            'template_id' => 77,
        ], $over);
    }

    public function test_a_kit_page_is_tagged_with_its_lp_kit_term(): void
    {
        $result = ( new ContentStore() )->upsert($this->payload());

        $terms = wp_get_object_terms($result['wp_post_id'], KitTaxonomy::TAXONOMY, ['fields' => 'slugs']);
        $this->assertContains('service-page', $terms);
    }

    public function test_the_resolved_template_id_is_stored(): void
    {
        $result = ( new ContentStore() )->upsert($this->payload());

        $this->assertSame(77, (int) get_post_meta($result['wp_post_id'], Meta::TEMPLATE_ID, true));
    }

    public function test_a_re_push_replaces_the_kit_term_and_clears_an_absent_template(): void
    {
        $store = new ContentStore();
        $first = $store->upsert($this->payload());

        // Re-kit the same content and drop the mapping.
        $second = $store->upsert($this->payload(['kit' => 'location-page', 'template_id' => null]));

        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);
        $terms = wp_get_object_terms($second['wp_post_id'], KitTaxonomy::TAXONOMY, ['fields' => 'slugs']);
        $this->assertSame(['location-page'], $terms);                                  // no stale marker
        $this->assertSame('', get_post_meta($second['wp_post_id'], Meta::TEMPLATE_ID, true)); // cleared
    }

    public function test_a_post_is_not_tagged_with_a_kit_term(): void
    {
        $result = ( new ContentStore() )->upsert($this->payload([
            'content_id' => '01JKITTEMPLATE00000000000B',
            'kind' => 'post',
            'kit' => '',
            'slug' => 'a-news-post',
        ]));

        $terms = wp_get_object_terms($result['wp_post_id'], KitTaxonomy::TAXONOMY, ['fields' => 'slugs']);
        $this->assertSame([], $terms);
    }
}
