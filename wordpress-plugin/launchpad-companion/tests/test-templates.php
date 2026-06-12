<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Rest\Templates;

class Test_Templates extends WP_UnitTestCase
{
    private function make_template(string $title, string $type, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'elementor_library',
            'post_title' => $title,
            'post_status' => $status,
        ]);
        update_post_meta($id, '_elementor_template_type', $type);

        return (int) $id;
    }

    private function type_of(array $payload, int $id): ?string
    {
        $row = current(array_filter($payload['templates'], static fn ($t) => $t['id'] === $id));

        return is_array($row) ? (string) $row['type'] : null;
    }

    public function test_theme_builder_templates_appear_with_their_actual_type(): void
    {
        // The bug: post_status=publish dropped Theme Builder templates, and the
        // page/container ones that remained all read "container". A single-page
        // template (like live #191) must appear, typed single-page.
        $singlePage = $this->make_template('Elementor Single Page', 'single-page');
        $header = $this->make_template('Site Header', 'header');
        $container = $this->make_template('A Container', 'container');

        $payload = Templates::payload();

        $this->assertSame('single-page', $this->type_of($payload, $singlePage));
        $this->assertSame('header', $this->type_of($payload, $header));
        $this->assertSame('container', $this->type_of($payload, $container)); // a real container stays a container
    }

    public function test_an_unpublished_theme_builder_template_is_not_omitted(): void
    {
        $draft = $this->make_template('Draft Single Post', 'single-post', 'draft');

        $ids = array_column(Templates::payload()['templates'], 'id');

        $this->assertContains($draft, $ids); // post_status=any
    }

    public function test_type_falls_back_to_the_library_type_taxonomy_when_meta_is_absent(): void
    {
        // v4-safety: if _elementor_template_type is empty, read the type from the
        // elementor_library_type taxonomy term instead of defaulting.
        $id = self::factory()->post->create(['post_type' => 'elementor_library', 'post_title' => 'Taxonomy Typed']);
        register_taxonomy('elementor_library_type', 'elementor_library');
        wp_set_object_terms($id, 'single-page', 'elementor_library_type');

        $this->assertSame('single-page', $this->type_of(Templates::payload(), (int) $id));
    }

    public function test_payload_enumerates_elementor_library_templates(): void
    {
        $single = $this->make_template('Blog Single', 'single-post');
        $page = $this->make_template('Service Page', 'page');

        $payload = Templates::payload();

        $this->assertArrayHasKey('templates', $payload);
        $ids = array_column($payload['templates'], 'id');
        $this->assertContains($single, $ids);
        $this->assertContains($page, $ids);

        $row = current(array_filter($payload['templates'], static fn ($t) => $t['id'] === $page));
        $this->assertSame('Service Page', $row['title']);
        $this->assertSame('page', $row['type']);
        $this->assertArrayHasKey('slug', $row);
        $this->assertArrayHasKey('modified', $row);
        $this->assertArrayHasKey('preview_url', $row);   // present (may be a permalink fallback)
        $this->assertArrayHasKey('thumbnail', $row);     // null when no screenshot/featured image
    }

    public function test_payload_ignores_non_library_posts(): void
    {
        $this->make_template('A Template', 'page');
        $ordinary = self::factory()->post->create(['post_type' => 'post', 'post_title' => 'Just a post']);

        $ids = array_column(Templates::payload()['templates'], 'id');

        $this->assertNotContains($ordinary, $ids);
    }

    public function test_templates_route_is_registered_under_the_contract_namespace(): void
    {
        $routes = rest_get_server()->get_routes('launchpad/v1');

        $this->assertArrayHasKey('/launchpad/v1/templates', $routes);
    }
}
