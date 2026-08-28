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

    public function test_header_emits_the_mobile_hamburger_toggle_when_there_is_a_nav(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'Sewer Gurus',
            'nav' => [['label' => 'About', 'url' => 'https://sewergurus.com/about']],
        ]);

        $header = (new SiteChrome())->header();

        $this->assertStringContainsString('lp-nav-checkbox', $header);
        $this->assertStringContainsString('lp-hamburger', $header);
    }

    public function test_header_omits_the_hamburger_when_there_is_no_nav(): void
    {
        ( new SiteProfileStore() )->save(['brand_name' => 'Sewer Gurus']);

        $this->assertStringNotContainsString('lp-hamburger', (new SiteChrome())->header());
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

    public function test_header_services_use_the_short_nav_label(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'SPG',
            'services' => [[
                'label' => 'Sump Pumps', 'url' => 'https://spg.com/sump-pumps', 'nav_label' => 'Sump Pumps',
                'children' => [
                    ['label' => 'Sump Pump Installation', 'url' => 'https://spg.com/sump-pumps/installation', 'nav_label' => 'Installation'],
                ],
            ]],
        ]);

        $header = (new SiteChrome())->header();

        // The header shows the short label; the full title never appears in the header menu.
        $this->assertStringContainsString('>Installation<', $header);
        $this->assertStringNotContainsString('Sump Pump Installation', $header);
    }

    public function test_footer_services_keep_the_full_title_not_the_short_label(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'SPG',
            'services' => [['label' => 'Sump Pump Installation', 'url' => 'https://spg.com/i', 'nav_label' => 'Installation']],
        ]);

        $footer = (new SiteChrome())->footer();

        // The footer keeps the keyword-rich full title, not the short header label.
        $this->assertStringContainsString('Sump Pump Installation', $footer);
        $this->assertStringNotContainsString('>Installation<', $footer);
    }

    public function test_services_menu_switches_to_mega_past_the_flat_threshold(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'SPG',
            'nav_menu' => ['flat_max' => 2, 'group_overflow' => 8],
            'services' => [
                ['label' => 'A', 'url' => 'https://x/a'],
                ['label' => 'B', 'url' => 'https://x/b'],
                ['label' => 'C', 'url' => 'https://x/c'],
            ],
        ]);

        $this->assertStringContainsString('lp-services-nav--mega', (new SiteChrome())->header());
    }

    public function test_services_menu_stays_flat_within_the_threshold(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'SPG',
            'nav_menu' => ['flat_max' => 6, 'group_overflow' => 8],
            'services' => [['label' => 'A', 'url' => 'https://x/a'], ['label' => 'B', 'url' => 'https://x/b']],
        ]);

        $this->assertStringContainsString('lp-services-nav--flat', (new SiteChrome())->header());
    }

    public function test_the_same_service_is_short_in_the_header_and_long_form_in_the_footer(): void
    {
        // Change 4 guard: one service, two surfaces. The header takes the short nav_label; the footer keeps
        // the full title (its keyword-rich internal-link anchor). If a future change "unifies" the two, this
        // fails.
        ( new SiteProfileStore() )->save([
            'brand_name' => 'SPG',
            'services' => [['label' => 'Sump Pump Installation', 'url' => 'https://spg.com/i', 'nav_label' => 'Installation']],
        ]);

        $chrome = new SiteChrome();
        $header = $chrome->header();
        $footer = $chrome->footer();

        $this->assertStringContainsString('>Installation<', $header);
        $this->assertStringNotContainsString('Sump Pump Installation', $header);
        $this->assertStringContainsString('Sump Pump Installation', $footer);
        $this->assertStringNotContainsString('>Installation<', $footer);
    }

    public function test_services_menu_overflows_extra_groups_into_more(): void
    {
        ( new SiteProfileStore() )->save([
            'brand_name' => 'SPG',
            'nav_menu' => ['flat_max' => 1, 'group_overflow' => 1],
            'services' => [
                ['label' => 'G1', 'url' => 'https://x/g1', 'children' => [['label' => 'c1', 'url' => 'https://x/c1']]],
                ['label' => 'G2', 'url' => 'https://x/g2', 'children' => [['label' => 'c2', 'url' => 'https://x/c2']]],
            ],
        ]);

        $header = (new SiteChrome())->header();

        // The second group folds into a trailing "More" dropdown carrying its hub link.
        $this->assertStringContainsString('>More<', $header);
        $this->assertStringContainsString('href="https://x/g2"', $header);
    }
}
