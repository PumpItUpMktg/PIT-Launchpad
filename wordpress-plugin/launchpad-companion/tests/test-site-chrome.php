<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\SiteProfileStore;
use Launchpad\Companion\Render\SiteChrome;

class Test_Site_Chrome extends WP_UnitTestCase
{
    public function test_footer_renders_the_legal_links_beside_the_copyright(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Sewer Gurus',
            'legal_links' => [
                ['label' => 'Privacy Policy', 'url' => 'https://sewergurus.com/privacy-policy'],
                ['label' => 'Terms of Service', 'url' => 'https://sewergurus.com/terms-of-service'],
            ],
        ]);

        $footer = (new SiteChrome())->footer();

        $this->assertStringContainsString('lp-flegal', $footer);
        $this->assertStringContainsString('href="https://sewergurus.com/privacy-policy"', $footer);
        $this->assertStringContainsString('Terms of Service', $footer);
    }

    public function test_footer_omits_the_legal_nav_when_no_legal_pages_exist(): void
    {
        ( new SiteProfileStore() )->save(['brand_name' => 'Sewer Gurus']);

        $this->assertStringNotContainsString('lp-flegal', (new SiteChrome())->footer());
    }

    public function test_header_tone_survives_the_store_sanitize(): void
    {
        // Regression: the sanitize whitelist silently stripped header_tone, forcing every header light.
        ( new SiteProfileStore() )->save(['brand_name' => 'Sewer Gurus', 'header_tone' => 'dark']);

        $this->assertStringContainsString('lp-tone-dark', (new SiteChrome())->header());
    }

    public function test_header_services_render_a_hub_with_its_spokes_as_a_dropdown(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Sewer Gurus',
            'services' => [
                [
                    'label' => 'Basement Waterproofing',
                    'url' => 'https://sewergurus.com/basement-waterproofing',
                    'children' => [
                        ['label' => 'Sump Pump', 'url' => 'https://sewergurus.com/basement-waterproofing/sump-pump'],
                        ['label' => 'French Drains', 'url' => 'https://sewergurus.com/basement-waterproofing/french-drains'],
                    ],
                ],
                ['label' => 'Radon Mitigation', 'url' => 'https://sewergurus.com/radon-mitigation'],
            ],
        ]);

        $header = (new SiteChrome())->header();

        // The hub is a dropdown parent; its spokes render inside the sub-nav.
        $this->assertStringContainsString('lp-has-sub', $header);
        $this->assertStringContainsString('lp-subnav', $header);
        $this->assertStringContainsString('href="https://sewergurus.com/basement-waterproofing/sump-pump"', $header);
        $this->assertStringContainsString('href="https://sewergurus.com/basement-waterproofing/french-drains"', $header);
        // The standalone service has no dropdown of its own.
        $this->assertStringContainsString('href="https://sewergurus.com/radon-mitigation"', $header);
    }

    public function test_footer_services_stay_flat_ignoring_children(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Sewer Gurus',
            'services' => [[
                'label' => 'Basement Waterproofing',
                'url' => 'https://sewergurus.com/basement-waterproofing',
                'children' => [['label' => 'Sump Pump', 'url' => 'https://sewergurus.com/basement-waterproofing/sump-pump']],
            ]],
        ]);

        // The footer renders services flat — no dropdown markup.
        $this->assertStringNotContainsString('lp-subnav', (new SiteChrome())->footer());
    }
}
