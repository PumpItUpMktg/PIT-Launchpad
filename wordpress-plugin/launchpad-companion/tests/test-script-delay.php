<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Render\ScriptDelay;

class Test_Script_Delay extends WP_UnitTestCase
{
    private function html(string $head): string
    {
        return "<html><head>{$head}</head><body><p>hi</p></body></html>";
    }

    public function test_delays_allowlisted_third_party_scripts(): void
    {
        $out = ScriptDelay::transform(
            $this->html(
                '<script src="https://maps.googleapis.com/maps/api/js?libraries=places"></script>'
                .'<script async src="https://www.googletagmanager.com/gtag/js?id=GT-WRF2X3D7"></script>'
                .'<script src="https://widgets.leadconnectorhq.com/loader.js?ver=4.0.4"></script>'
            ),
            ScriptDelay::default_hosts()
        );

        // Each becomes an inert placeholder carrying its URL, and no executable src remains for them.
        $this->assertStringContainsString('data-lp-src="https://maps.googleapis.com/maps/api/js?libraries=places"', $out);
        $this->assertStringContainsString('data-lp-src="https://www.googletagmanager.com/gtag/js?id=GT-WRF2X3D7"', $out);
        $this->assertSame(3, substr_count($out, 'data-lp-delayed="1"'));
        $this->assertStringNotContainsString(' src="https://maps.googleapis.com', $out);
        // The loader is printed exactly once, before </body>.
        $this->assertSame(1, substr_count($out, 'id="lp-delay-loader"'));
    }

    public function test_never_touches_first_party_or_inline_scripts(): void
    {
        $head = '<script src="/wp-includes/js/jquery.min.js"></script>'
            .'<script src="https://sumppumpgurus.com/wp-content/themes/x/app.js"></script>'
            .'<script>window.dataLayer=[];</script>';

        $out = ScriptDelay::transform($this->html($head), ScriptDelay::default_hosts());

        $this->assertStringContainsString('<script src="/wp-includes/js/jquery.min.js"></script>', $out);
        $this->assertStringContainsString('themes/x/app.js"></script>', $out);
        $this->assertStringContainsString('<script>window.dataLayer=[];</script>', $out);
        // Nothing matched → no loader injected.
        $this->assertStringNotContainsString('lp-delay-loader', $out);
    }

    public function test_matches_subdomains_and_is_idempotent(): void
    {
        $one = ScriptDelay::transform(
            $this->html('<script src="https://static.cloudflareinsights.com/beacon.min.js"></script>'),
            ['cloudflareinsights.com']
        );
        $this->assertStringContainsString('data-lp-delayed="1"', $one); // subdomain matched via the base host

        // Re-running over already-delayed output changes nothing further.
        $twice = ScriptDelay::transform($one, ['cloudflareinsights.com']);
        $this->assertSame(substr_count($one, 'data-lp-delayed="1"'), substr_count($twice, 'data-lp-delayed="1"'));
    }

    public function test_respects_a_filtered_host_allowlist(): void
    {
        // A host NOT on the (custom) allowlist is left executable.
        $out = ScriptDelay::transform(
            $this->html('<script src="https://maps.googleapis.com/maps/api/js"></script>'),
            ['example.com']
        );
        $this->assertStringContainsString('<script src="https://maps.googleapis.com/maps/api/js"></script>', $out);
        $this->assertStringNotContainsString('data-lp-delayed', $out);
    }
}
