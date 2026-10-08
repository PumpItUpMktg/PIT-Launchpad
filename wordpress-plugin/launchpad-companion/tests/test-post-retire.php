<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Content\PostRetirer;
use Launchpad\Companion\Content\RedirectStore;
use Launchpad\Companion\Meta;

class Test_Post_Retire extends WP_UnitTestCase
{
    public function test_retires_a_legacy_post_only_when_a_redirect_covers_its_path(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $legacy = self::factory()->post->create(['post_name' => 'sump-pump-cost-2', 'post_status' => 'publish']);

        // No redirect yet → refused: retiring it would 404 a live URL.
        $refused = (new PostRetirer())->retire('/sump-pump-cost-2/');
        $this->assertFalse($refused['retired']);
        $this->assertStringContainsString('no redirect', $refused['error']);
        $this->assertSame('publish', get_post_status($legacy));

        (new RedirectStore())->upsert([['from_url' => '/sump-pump-cost-2/', 'to_url' => '/sump-pump-cost-3/', 'code' => 301]]);

        $done = (new PostRetirer())->retire('/sump-pump-cost-2/');
        $this->assertTrue($done['retired']);
        $this->assertSame($legacy, $done['wp_post_id']);
        $this->assertSame('trash', get_post_status($legacy), 'Trashed, never force-deleted.');

        // Idempotent: nothing is served there any more.
        $again = (new PostRetirer())->retire('/sump-pump-cost-2/');
        $this->assertTrue($again['retired']);
        $this->assertTrue($again['already_absent']);
    }

    public function test_refuses_a_launchpad_owned_post(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $ours = self::factory()->post->create(['post_name' => 'our-guide-2', 'post_status' => 'publish']);
        update_post_meta($ours, Meta::CONTENT_ID, '01JOURS000000000000000000');
        (new RedirectStore())->upsert([['from_url' => '/our-guide-2/', 'to_url' => '/our-guide/', 'code' => 301]]);

        $r = (new PostRetirer())->retire('/our-guide-2/');

        $this->assertFalse($r['retired']);
        $this->assertStringContainsString('Launchpad-owned', $r['error']);
        $this->assertSame('publish', get_post_status($ours));
    }

    public function test_resolves_the_post_by_slug_when_permalinks_are_plain(): void
    {
        // The default test site has no pretty permalinks, so url_to_postid() cannot parse the path.
        $legacy = self::factory()->post->create(['post_name' => 'old-guide-3', 'post_status' => 'publish']);
        (new RedirectStore())->upsert([['from_url' => '/old-guide-3/', 'to_url' => '/old-guide/', 'code' => 301]]);

        $r = (new PostRetirer())->retire('/old-guide-3/');

        $this->assertTrue($r['retired']);
        $this->assertSame($legacy, $r['wp_post_id']);
        $this->assertSame('trash', get_post_status($legacy));
    }

    public function test_rejects_an_empty_path(): void
    {
        $this->assertSame('path required', (new PostRetirer())->retire('')['error']);
        $this->assertSame('path required', (new PostRetirer())->retire('/')['error']);
    }
}
