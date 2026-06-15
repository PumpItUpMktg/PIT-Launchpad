<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\BrandKitStore;
use Launchpad\Companion\Meta;

class Test_Brand_Kit_Store extends WP_UnitTestCase
{
    private int $kit_id;

    public function set_up(): void
    {
        parent::set_up();
        // A stand-in active Global Kit (the store reads the option + page settings
        // meta; it needs no real Elementor runtime).
        $this->kit_id = (int) self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Default Kit']);
        update_option('elementor_active_kit', $this->kit_id);
    }

    private function settings(): array
    {
        $settings = get_post_meta($this->kit_id, '_elementor_page_settings', true);

        return is_array($settings) ? $settings : [];
    }

    private function color_for(string $id): ?string
    {
        foreach ($this->settings()['system_colors'] ?? [] as $c) {
            if (($c['_id'] ?? '') === $id) {
                return (string) ($c['color'] ?? '');
            }
        }

        return null;
    }

    private function font_for(string $id): ?array
    {
        foreach ($this->settings()['system_typography'] ?? [] as $t) {
            if (($t['_id'] ?? '') === $id) {
                return $t;
            }
        }

        return null;
    }

    public function test_it_writes_system_colors_into_the_active_kit(): void
    {
        $result = ( new BrandKitStore() )->install([
            'colors' => ['primary' => '#0F62FE', 'accent' => '#FF6F00'],
        ]);

        $this->assertTrue($result['updated']);
        $this->assertSame($this->kit_id, $result['kit_id']);
        $this->assertSame(2, $result['colors_set']);
        $this->assertSame('#0f62fe', strtolower((string) $this->color_for('primary')));
        $this->assertSame('#ff6f00', strtolower((string) $this->color_for('accent')));
    }

    public function test_it_writes_system_typography_as_custom_fonts(): void
    {
        ( new BrandKitStore() )->install([
            'fonts' => ['primary' => ['family' => 'Inter', 'weight' => '700'], 'text' => ['family' => 'Georgia']],
        ]);

        $primary = $this->font_for('primary');
        $this->assertSame('custom', $primary['typography_typography']);
        $this->assertSame('Inter', $primary['typography_font_family']);
        $this->assertSame('700', $primary['typography_font_weight']);
        $this->assertSame('Georgia', $this->font_for('text')['typography_font_family']);
    }

    public function test_a_re_push_overwrites_the_same_slot_not_duplicates_it(): void
    {
        $store = new BrandKitStore();
        $store->install(['colors' => ['primary' => '#111111']]);
        $store->install(['colors' => ['primary' => '#222222']]);

        $primaries = array_filter(
            $this->settings()['system_colors'],
            static fn ($c) => ($c['_id'] ?? '') === 'primary'
        );
        $this->assertCount(1, $primaries);                 // no duplicate slot
        $this->assertSame('#222222', $this->color_for('primary')); // latest wins
    }

    public function test_it_preserves_unrelated_existing_kit_settings(): void
    {
        update_post_meta($this->kit_id, '_elementor_page_settings', [
            'site_name' => 'Acme',
            'system_colors' => [['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#ABCABC']],
        ]);

        ( new BrandKitStore() )->install(['colors' => ['primary' => '#0F62FE']]);

        $this->assertSame('Acme', $this->settings()['site_name']);             // untouched
        $this->assertSame('#ABCABC', $this->color_for('secondary'));           // kept
        $this->assertSame('#0f62fe', strtolower((string) $this->color_for('primary'))); // added
    }

    public function test_a_non_hex_color_value_passes_through(): void
    {
        ( new BrandKitStore() )->install(['colors' => ['primary' => 'rgb(15, 98, 254)']]);

        $this->assertSame('rgb(15, 98, 254)', $this->color_for('primary'));
    }

    public function test_no_active_kit_is_a_soft_failure(): void
    {
        delete_option('elementor_active_kit');

        $result = ( new BrandKitStore() )->install(['colors' => ['primary' => '#0F62FE']]);

        $this->assertFalse($result['updated']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_an_empty_brand_kit_is_a_soft_failure(): void
    {
        $result = ( new BrandKitStore() )->install(['colors' => [], 'fonts' => []]);

        $this->assertFalse($result['updated']);
        $this->assertSame(0, $result['colors_set']);
    }

    public function test_it_stores_the_native_wf_tokens_and_structure_options(): void
    {
        $result = ( new BrandKitStore() )->install([
            'wf_tokens' => [
                '--wf-color-primary' => '#1B3A5B',
                '--wf-font-heading' => 'Archivo',
                'color' => 'red',          // not a --wf-* name → dropped
                '--wf-bad' => ['x'],       // non-scalar → dropped
            ],
            'structure' => 'bold',
        ]);

        $this->assertSame(2, $result['wf_tokens_set']);
        $this->assertTrue($result['structure_set']);
        $this->assertSame(
            ['--wf-color-primary' => '#1B3A5B', '--wf-font-heading' => 'Archivo'],
            get_option(Meta::OPTION_BRAND_TOKENS)
        );
        $this->assertSame('bold', get_option(Meta::OPTION_STRUCTURE_PRESET));
    }

    public function test_an_invalid_structure_is_ignored(): void
    {
        $result = ( new BrandKitStore() )->install(['wf_tokens' => ['--wf-color-primary' => '#111'], 'structure' => 'nope']);

        $this->assertFalse($result['structure_set']);
        $this->assertFalse(get_option(Meta::OPTION_STRUCTURE_PRESET));
    }

    public function test_the_wf_layer_is_stored_even_with_no_active_kit(): void
    {
        delete_option('elementor_active_kit'); // no Elementor Global Kit

        $result = ( new BrandKitStore() )->install([
            'wf_tokens' => ['--wf-color-primary' => '#1B3A5B'],
            'structure' => 'warm',
        ]);

        // The native wf-* layer alone is a successful push (native pages don't need
        // the Elementor kit), even though the Global Kit write soft-failed.
        $this->assertTrue($result['updated']);
        $this->assertSame(1, $result['wf_tokens_set']);
        $this->assertSame('warm', get_option(Meta::OPTION_STRUCTURE_PRESET));
        $this->assertSame(['--wf-color-primary' => '#1B3A5B'], get_option(Meta::OPTION_BRAND_TOKENS));
    }
}
