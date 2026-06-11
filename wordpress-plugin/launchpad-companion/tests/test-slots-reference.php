<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Admin\SlotsScreen;
use Launchpad\Companion\Content\ContentStore;
use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\ShortcodeReference;

class Test_Slots_Reference extends WP_UnitTestCase
{
    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function payload(array $over = []): array
    {
        return array_merge([
            'content_id' => '01JCONTENTREF00000000000',
            'kind' => 'page',
            'page_type' => 'service',
            'kit' => 'service-page',
            'kit_version' => '2',
            'slug' => 'water-heater-repair',
            'status' => 'published',
            'slot_payload' => ['hero_problem' => 'No hot water'],
            'kit_definition' => [
                ['key' => 'hero_problem', 'label' => 'Hero Problem', 'content_type' => 'heading', 'cardinality' => ['type' => 'single', 'min' => null, 'max' => null], 'required' => true],
                ['key' => 'faq', 'label' => 'FAQ', 'content_type' => 'faq', 'cardinality' => ['type' => 'repeater', 'min' => 3, 'max' => 8], 'required' => true],
            ],
        ], $over);
    }

    public function test_upsert_stores_the_kit_definition_per_kit_and_version(): void
    {
        ( new ContentStore() )->upsert($this->payload());

        $defs = get_option(Meta::OPTION_KIT_DEFINITIONS, []);
        $this->assertArrayHasKey('service-page@2', $defs);
        $this->assertSame('faq', $defs['service-page@2']['slots'][1]['content_type']);
    }

    public function test_reference_maps_content_types_to_shortcode_classes_and_scalar_flag(): void
    {
        $faq = ShortcodeReference::for_type('faq', 'faq');
        $this->assertSame('[lp_repeater key="faq"]', $faq['shortcode']);
        $this->assertStringContainsString('lp-faq', $faq['classes']);
        $this->assertFalse($faq['scalar']);

        $hero = ShortcodeReference::for_type('heading', 'hero_problem');
        $this->assertSame('[lp_slot key="hero_problem"]', $hero['shortcode']);
        $this->assertTrue($hero['scalar']);
        $this->assertSame('lp_slot_hero_problem', ShortcodeReference::mirror_key('hero_problem'));

        $this->assertSame('[lp_cta key="cta"]', ShortcodeReference::for_type('cta', 'cta')['shortcode']);
        $this->assertSame('[lp_image key="hero_image"]', ShortcodeReference::for_type('image', 'hero_image')['shortcode']);
    }

    public function test_screen_renders_the_pushed_kit_with_copyable_shortcodes(): void
    {
        ( new ContentStore() )->upsert($this->payload());
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        ( new SlotsScreen() )->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Slots &amp; Shortcodes', $html);
        $this->assertStringContainsString('service-page', $html);
        $this->assertStringContainsString('[lp_repeater key=&quot;faq&quot;]', $html); // copyable shortcode (esc_html)
        $this->assertStringContainsString('lp_slot_hero_problem', $html);              // scalar mirror key
        $this->assertStringContainsString('lp-copy', $html);                            // copy buttons
    }

    public function test_screen_shows_an_empty_state_with_no_kits(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        ( new SlotsScreen() )->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('No kits have been pushed yet', $html);
    }

    public function test_builtin_posts_reference_always_renders_independent_of_kit_pushes(): void
    {
        // No kit pushed — the Posts section (body binding + SEO fields) must still
        // appear; it's the most-used binding and never rides a kit definition.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        ob_start();
        ( new SlotsScreen() )->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('>Posts <', $html);                      // built-in section heading
        $this->assertStringContainsString('[lp_slot key=&quot;body&quot;]', $html); // body shortcode (esc_html)
        $this->assertStringContainsString('lp_slot_body', $html);                   // body mirror key
        $this->assertStringContainsString('SEO fields', $html);                     // SEO bindings
        $this->assertStringContainsString('canonical', $html);                      // an SEO field
        $this->assertStringContainsString('No kits have been pushed yet', $html);   // kit area still shows its empty state
    }
}
