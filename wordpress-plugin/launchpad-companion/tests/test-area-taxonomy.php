<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\AreaTaxonomy;
use Launchpad\Companion\Content\ContentStore;

class Test_Area_Taxonomy extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        AreaTaxonomy::register();
    }

    public function test_lp_area_is_registered_public_and_queryable(): void
    {
        $taxonomy = get_taxonomy(AreaTaxonomy::TAXONOMY);

        $this->assertNotFalse($taxonomy);
        $this->assertTrue($taxonomy->public);
        $this->assertTrue($taxonomy->publicly_queryable);
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'content_id' => '01JAREATAXONOMY0000000000A',
            'kind' => 'post',
            'page_type' => 'post',
            'slug' => 'water-main-break',
            'status' => 'published',
            'slot_payload' => ['body' => 'A story.'],
            'towns' => [
                ['slug' => 'new-brunswick', 'name' => 'New Brunswick'],
                ['slug' => 'edison', 'name' => 'Edison'],
            ],
        ], $over);
    }

    public function test_a_post_is_tagged_with_every_town_it_references(): void
    {
        $result = ( new ContentStore() )->upsert($this->payload());

        $terms = wp_get_object_terms($result['wp_post_id'], AreaTaxonomy::TAXONOMY, ['fields' => 'names']);
        sort($terms);
        $this->assertSame(['Edison', 'New Brunswick'], $terms);
    }

    public function test_a_re_push_replaces_the_town_set_and_an_empty_list_clears_it(): void
    {
        $store = new ContentStore();
        $first = $store->upsert($this->payload());

        // The post now references only Edison; a re-push syncs the town set down.
        $second = $store->upsert($this->payload(['towns' => [['slug' => 'edison', 'name' => 'Edison']]]));
        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);
        $terms = wp_get_object_terms($second['wp_post_id'], AreaTaxonomy::TAXONOMY, ['fields' => 'names']);
        $this->assertSame(['Edison'], $terms);

        // No towns at all → cleared.
        $third = $store->upsert($this->payload(['towns' => []]));
        $terms = wp_get_object_terms($third['wp_post_id'], AreaTaxonomy::TAXONOMY, ['fields' => 'names']);
        $this->assertSame([], $terms);
    }

    public function test_a_location_page_can_query_the_posts_for_its_town(): void
    {
        $store = new ContentStore();
        $store->upsert($this->payload());
        $store->upsert($this->payload([
            'content_id' => '01JAREATAXONOMY0000000000B',
            'slug' => 'another-edison-story',
            'towns' => [['slug' => 'edison', 'name' => 'Edison']],
        ]));

        $ids = get_posts([
            'post_type' => 'post',
            'numberposts' => -1,
            'fields' => 'ids',
            'tax_query' => [[
                'taxonomy' => AreaTaxonomy::TAXONOMY,
                'field' => 'name',
                'terms' => 'Edison',
            ]],
        ]);

        // Both posts mention Edison; the New-Brunswick-only case is excluded by construction.
        $this->assertCount(2, $ids);
    }
}
