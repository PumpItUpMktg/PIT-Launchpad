<?php
/**
 * @package Launchpad\Companion
 */

use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\Shortcodes;

class Test_Shortcodes extends WP_UnitTestCase
{
    private int $post_id;

    public function set_up(): void
    {
        parent::set_up();
        ( new Shortcodes() )->register();

        $this->post_id = (int) self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($this->post_id, Meta::CONTENT_ID, '01JSHORTCODE0000000000000');
        update_post_meta($this->post_id, Meta::SLOTS, [
            'hero_problem' => '<p>No hot water when you need it?</p>',
            'service_features' => ['Endless hot water', 'Lower bills'],
            'faq' => [['question' => 'How long does install take?', 'answer' => 'Same day.']],
            'cta' => ['label' => 'Call now', 'url' => 'https://acme.com/contact'],
        ]);
        update_post_meta($this->post_id, Meta::IMAGES, [
            'hero_image' => ['url' => 'https://r2.example/hero.jpg', 'alt' => 'A water heater'],
        ]);
    }

    private function sc(string $tag, string $key): string
    {
        return do_shortcode("[{$tag} key=\"{$key}\" id=\"{$this->post_id}\"]");
    }

    public function test_lp_slot_renders_scalar_html(): void
    {
        $this->assertStringContainsString('No hot water', $this->sc('lp_slot', 'hero_problem'));
    }

    public function test_lp_slot_infers_a_list_repeater(): void
    {
        $out = $this->sc('lp_slot', 'service_features');
        $this->assertStringContainsString('lp-repeater', $out);
        $this->assertStringContainsString('Endless hot water', $out);
    }

    public function test_lp_repeater_renders_a_faq(): void
    {
        $out = $this->sc('lp_repeater', 'faq');
        $this->assertStringContainsString('lp-faq', $out);
        $this->assertStringContainsString('Same day', $out);
    }

    public function test_lp_repeater_faq_is_a_native_details_accordion(): void
    {
        $out = $this->sc('lp_repeater', 'faq');
        $this->assertStringContainsString('<details class="lp-faq">', $out);
        $this->assertStringContainsString('<summary class="lp-faq__q">How long does install take?</summary>', $out);
        $this->assertStringContainsString('lp-faq__a', $out);
    }

    public function test_lp_cta_renders_an_anchor(): void
    {
        $out = $this->sc('lp_cta', 'cta');
        $this->assertStringContainsString('href="https://acme.com/contact"', $out);
        $this->assertStringContainsString('Call now', $out);
    }

    public function test_lp_cta_renders_the_dual_conversion_block(): void
    {
        update_post_meta($this->post_id, Meta::SLOTS, [
            'cta' => [
                'type' => 'conversion_block',
                'call_label' => 'Call Now',
                'phone' => '+15125550142',
                'tel' => 'tel:+15125550142',
                'form_embed' => '<iframe src="https://api.leadconnectorhq.com/widget/form/abc"></iframe>'
                    . '<script src="https://link.msgsndr.com/js/form_embed.js"></script>',
            ],
        ]);

        $out = $this->sc('lp_cta', 'cta');
        $this->assertStringContainsString('lp-conversion-block', $out);
        $this->assertStringContainsString('href="tel:+15125550142"', $out);
        $this->assertStringContainsString('Call Now', $out);
        $this->assertStringContainsString('leadconnectorhq.com/widget/form/abc', $out); // GHL embed rendered
    }

    public function test_lp_cta_conversion_block_is_call_only_without_a_form(): void
    {
        update_post_meta($this->post_id, Meta::SLOTS, [
            'cta' => ['type' => 'conversion_block', 'call_label' => 'Call Now', 'phone' => '+15125550142', 'tel' => 'tel:+15125550142'],
        ]);

        $out = $this->sc('lp_cta', 'cta');
        $this->assertStringContainsString('href="tel:+15125550142"', $out);
        $this->assertStringNotContainsString('lp-conversion-block__form', $out); // no form → graceful call-only
    }

    public function test_lp_cta_renders_a_nap_contact_block(): void
    {
        update_post_meta($this->post_id, Meta::SLOTS, [
            'contact_block' => ['type' => 'nap', 'name' => 'Trooper Plumbing', 'address' => '1 Main St', 'phone' => '+15125550142'],
        ]);

        $out = $this->sc('lp_cta', 'contact_block');
        $this->assertStringContainsString('lp-nap', $out);
        $this->assertStringContainsString('Trooper Plumbing', $out);
        $this->assertStringContainsString('href="tel:+15125550142"', $out);
    }

    public function test_lp_image_renders_an_img_from_the_r2_url(): void
    {
        $out = $this->sc('lp_image', 'hero_image');
        $this->assertStringContainsString('<img', $out);
        $this->assertStringContainsString('https://r2.example/hero.jpg', $out);
    }

    public function test_renders_nothing_for_an_unmanaged_post(): void
    {
        $plain = (int) self::factory()->post->create();
        $this->assertSame('', do_shortcode("[lp_slot key=\"hero_problem\" id=\"{$plain}\"]"));
    }
}
