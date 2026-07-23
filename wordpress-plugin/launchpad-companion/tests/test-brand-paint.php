<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Render\BrandPaint;

class Test_Brand_Paint extends WP_UnitTestCase
{
    public function test_root_block_redeclares_preset_color_variables(): void
    {
        $css = BrandPaint::root_block([
            'colors' => ['primary' => '#1E5233', 'accent' => '#4C9A2A', 'button' => '#3E7D2B'],
            'custom' => ['radius' => '14px', 'heading_weight' => '700'],
        ]);

        $this->assertStringStartsWith(':root{', $css);
        $this->assertStringContainsString('--wp--preset--color--primary:#1e5233;', $css);
        $this->assertStringContainsString('--wp--preset--color--accent:#4c9a2a;', $css);
        $this->assertStringContainsString('--wp--preset--color--button:#3e7d2b;', $css);
        $this->assertStringContainsString('--wp--custom--radius:14px;', $css);
        $this->assertStringContainsString('--wp--custom--heading-weight:700;', $css);
    }

    public function test_root_block_is_empty_when_no_colors_or_tokens(): void
    {
        $this->assertSame('', BrandPaint::root_block([]));
        $this->assertSame('', BrandPaint::root_block(['colors' => [], 'custom' => []]));
    }

    public function test_root_block_drops_non_hex_colors_and_unsafe_tokens(): void
    {
        $css = BrandPaint::root_block([
            'colors' => ['primary' => '#1E5233', 'accent' => 'red; } body{display:none', 'button' => 'var(--x)'],
            'custom' => ['radius' => '12px}<script>', 'heading_weight' => '700'],
        ]);

        // Only the clean hex survives among the colors; no CSS breakout can appear.
        $this->assertStringContainsString('--wp--preset--color--primary:#1e5233;', $css);
        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString('var(--x)', $css);
        $this->assertStringNotContainsString('<script>', $css);
        $this->assertStringNotContainsString('}b', $css);
        // The shape token is sanitized to a safe charset (braces/brackets stripped) but still applied.
        $this->assertStringContainsString('--wp--custom--radius:12px', $css);
        $this->assertStringContainsString('--wp--custom--heading-weight:700;', $css);
    }
}
