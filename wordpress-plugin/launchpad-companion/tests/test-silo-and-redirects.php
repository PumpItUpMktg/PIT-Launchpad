<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\RedirectStore;
use Launchpad\Companion\Content\SiloStore;

class Test_Silo_And_Redirects extends WP_UnitTestCase
{
    public function test_silo_creates_a_category_with_parent_and_is_idempotent(): void
    {
        $store = new SiloStore();

        $parent = $store->ensure(['silo_id' => '01JPARENT0000000000000000', 'name' => 'Plumbing']);
        $child = $store->ensure(['silo_id' => '01JCHILD00000000000000000', 'name' => 'Water Heaters', 'parent_silo_id' => '01JPARENT0000000000000000']);

        $this->assertGreaterThan(0, $parent['wp_category_id']);
        $term = get_term($child['wp_category_id'], 'category');
        $this->assertSame($parent['wp_category_id'], (int) $term->parent, 'Child silo links to the parent category.');

        // Re-push the same silo → same term, no duplicate.
        $again = $store->ensure(['silo_id' => '01JCHILD00000000000000000', 'name' => 'Water Heaters (renamed)']);
        $this->assertSame($child['wp_category_id'], $again['wp_category_id']);
        $this->assertSame('Water Heaters (renamed)', get_term($again['wp_category_id'], 'category')->name);
    }

    public function test_silo_sets_the_category_description_and_a_later_empty_push_keeps_it(): void
    {
        $store = new SiloStore();

        $created = $store->ensure([
            'silo_id' => '01JDESC000000000000000000', 'name' => 'Sewer & Water Lines',
            'description' => 'Everything sewer and water-line — repairs, replacements, and leak detection.',
        ]);
        $this->assertSame(
            'Everything sewer and water-line — repairs, replacements, and leak detection.',
            get_term($created['wp_category_id'], 'category')->description
        );

        // A re-push WITHOUT a description (e.g. before the pillar page exists) must NOT clear it.
        $store->ensure(['silo_id' => '01JDESC000000000000000000', 'name' => 'Sewer & Water Lines']);
        $this->assertSame(
            'Everything sewer and water-line — repairs, replacements, and leak detection.',
            get_term($created['wp_category_id'], 'category')->description
        );
    }

    public function test_redirects_replace_the_full_set_and_drop_stale(): void
    {
        $store = new RedirectStore();

        $store->upsert([
            ['from_url' => '/old-a', 'to_url' => '/new-a', 'code' => 301],
            ['from_url' => '/old-b', 'to_url' => '/new-b', 'code' => 302],
        ]);

        // Second push omits /old-b → it must be dropped (full-set replace).
        $count = $store->upsert([
            ['from_url' => '/old-a', 'to_url' => '/new-a2', 'code' => 301],
        ]);

        $this->assertSame(1, $count, 'Stale redirects not in the latest push are dropped.');
        $map = get_option(\Launchpad\Companion\Meta::OPTION_REDIRECTS, []);
        $this->assertArrayHasKey('/old-a', $map);
        $this->assertArrayNotHasKey('/old-b', $map);
        $this->assertSame('/new-a2', $map['/old-a']['to_url']);
    }

    public function test_redirect_path_normalization(): void
    {
        $this->assertSame('/foo/bar', RedirectStore::normalize('https://site.com/foo/bar/'));
        $this->assertSame('/foo', RedirectStore::normalize('/foo?utm=1'));
    }
}
