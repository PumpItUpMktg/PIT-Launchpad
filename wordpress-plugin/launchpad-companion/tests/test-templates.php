<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Rest\Templates;

class Test_Templates extends WP_UnitTestCase
{
    private function make_template(string $title, string $type): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'elementor_library',
            'post_title' => $title,
            'post_status' => 'publish',
        ]);
        update_post_meta($id, '_elementor_template_type', $type);

        return (int) $id;
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
