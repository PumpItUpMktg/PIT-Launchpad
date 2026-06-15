<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\Assets;
use Launchpad\Companion\Render\TemplateRouter;

class Test_Brand_Tokens extends WP_UnitTestCase
{
    public function test_root_block_emits_only_valid_wf_tokens(): void
    {
        $css = Assets::root_block([
            '--wf-color-primary' => '#1B3A5B',
            '--wf-font-heading' => 'Archivo',
            'color' => 'red',                    // not a --wf-* name → dropped
            '--wf-evil' => 'red;} body{display:none', // breakout chars stripped
        ]);

        $this->assertStringStartsWith(':root{', $css);
        $this->assertStringContainsString('--wf-color-primary:#1B3A5B;', $css);
        $this->assertStringContainsString('--wf-font-heading:Archivo;', $css);
        $this->assertStringNotContainsString('color:red', $css);   // non-wf dropped
        $this->assertStringNotContainsString('}', substr($css, 0, strlen($css) - 1)); // no interior brace
        $this->assertStringNotContainsString('display:none', $css); // breakout neutralized
    }

    public function test_root_block_is_empty_with_no_tokens(): void
    {
        $this->assertSame('', Assets::root_block([]));
    }

    public function test_managed_page_gets_the_pushed_structure_body_class(): void
    {
        update_option(Meta::OPTION_STRUCTURE_PRESET, 'bold');
        $id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($id, Meta::PAGE_TYPE, 'service');
        $this->go_to(get_permalink($id));

        $classes = (new TemplateRouter())->body_class([]);

        $this->assertContains('wf-structure-bold', $classes);
        $this->assertContains('lp-page-type-service', $classes);
        delete_option(Meta::OPTION_STRUCTURE_PRESET);
    }

    public function test_structure_class_defaults_to_trust_and_rejects_garbage(): void
    {
        update_option(Meta::OPTION_STRUCTURE_PRESET, 'not-a-preset');
        $id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($id, Meta::PAGE_TYPE, 'service');
        $this->go_to(get_permalink($id));

        $this->assertContains('wf-structure-trust', (new TemplateRouter())->body_class([]));
        delete_option(Meta::OPTION_STRUCTURE_PRESET);
    }

    public function test_a_non_managed_page_gets_no_structure_class(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page']); // no lp meta
        $this->go_to(get_permalink($id));

        $classes = (new TemplateRouter())->body_class([]);
        $this->assertEmpty(array_filter($classes, static fn ($c) => str_starts_with($c, 'wf-structure-')));
    }
}
