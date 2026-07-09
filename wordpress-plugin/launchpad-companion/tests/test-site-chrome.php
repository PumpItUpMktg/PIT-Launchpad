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
}
