<?php
/**
 * Launchpad slot shortcodes — the Elementor-version-independent binding surface.
 * Operators place these in a Theme Builder template (a Shortcode/Text widget) to
 * render pushed slot content, with NO dependency on Elementor Pro dynamic tags.
 * This is the durable path for repeater/cta/map/image slots that the readable
 * `lp_slot_*` meta mirror can't represent, and it works on the Atomic Editor (V4),
 * the classic editor, or no Elementor at all.
 *
 *   [lp_slot key="hero_problem"]      scalar / HTML (also infers list/cta/map)
 *   [lp_repeater key="faq"]           faq / features / testimonials / stats
 *   [lp_cta key="cta"]                a {label,url} call-to-action anchor
 *   [lp_map key="service_area"]       a {embed_url} or {lat,lng} map
 *   [lp_image key="hero_image"]       an <img> from the R2/CDN image map
 *
 * Every shortcode accepts an optional id="<post_id>"; it defaults to the rendered
 * post and renders nothing for a non-managed post.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

if (! defined('ABSPATH')) {
    exit;
}

final class Shortcodes
{
    public function register(): void
    {
        add_shortcode('lp_slot', [$this, 'slot']);
        add_shortcode('lp_repeater', [$this, 'repeater']);
        add_shortcode('lp_cta', [$this, 'cta']);
        add_shortcode('lp_map', [$this, 'map']);
        add_shortcode('lp_image', [$this, 'image']);
        add_shortcode('lp_form', [$this, 'form']);
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function slot($atts): string
    {
        [$id, $key] = $this->resolve($atts, 'lp_slot');
        if ($key === '') {
            return '';
        }

        $value = Payload::slot($id, $key);

        if (is_string($value)) {
            return SlotRenderer::text($value);
        }

        if (is_array($value)) {
            if (self::is_list($value)) {
                return SlotRenderer::repeater($key, $value);
            }
            if (! empty($value['embed_url']) || (isset($value['lat'], $value['lng']))) {
                return SlotRenderer::map($value);
            }
            if (! empty($value['url']) || ! empty($value['label'])) {
                return SlotRenderer::cta($value);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function repeater($atts): string
    {
        [$id, $key] = $this->resolve($atts, 'lp_repeater');

        return $key === '' ? '' : SlotRenderer::repeater($key, Payload::slot($id, $key));
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function cta($atts): string
    {
        [$id, $key] = $this->resolve($atts, 'lp_cta');

        return $key === '' ? '' : SlotRenderer::cta(Payload::slot($id, $key));
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function map($atts): string
    {
        [$id, $key] = $this->resolve($atts, 'lp_map');

        return $key === '' ? '' : SlotRenderer::map(Payload::slot($id, $key));
    }

    /**
     * @param  array<string, mixed>|string  $atts
     */
    public function image($atts): string
    {
        [$id, $key] = $this->resolve($atts, 'lp_image');

        return $key === '' ? '' : SlotRenderer::image(Payload::image($id, $key));
    }

    /**
     * Resolve [post_id, key] from the shortcode atts; returns key '' (render
     * nothing) when no key is given or the post isn't a managed Launchpad post.
     *
     * @param  array<string, mixed>|string  $atts
     * @return array{0: int, 1: string}
     */
    /**
     * The page's lead-form embed (a GHL iframe, operator-configured on the control plane and
     * stored in FORM_EMBED meta — kses strips iframes from post_content, so it renders here).
     * Operator-only input via the authed contract (the same trust boundary as the Elementor
     * form-hero before it); renders nothing when no embed is configured.
     *
     * @param  array<string, mixed>|string  $atts
     */
    public function form($atts): string
    {
        $atts = shortcode_atts(['id' => 0], is_array($atts) ? $atts : [], 'lp_form');
        $id = (int) $atts['id'] > 0 ? (int) $atts['id'] : Payload::current_id();

        if ($id <= 0 || ! Payload::is_managed($id)) {
            return '';
        }

        $embed = trim((string) get_post_meta($id, Meta::FORM_EMBED, true));
        if ($embed === '') {
            return '';
        }

        return '<div class="lp-form-embed">' . $embed . '</div>';
    }

    private function resolve($atts, string $tag): array
    {
        $atts = shortcode_atts(['key' => '', 'id' => 0], $atts, $tag);

        $id = (int) $atts['id'] > 0 ? (int) $atts['id'] : Payload::current_id();
        $key = trim((string) $atts['key']);

        if ($key === '' || ! Payload::is_managed($id)) {
            return [$id, ''];
        }

        return [$id, $key];
    }

    /**
     * PHP 8.0-safe list check (array_is_list is 8.1+; the plugin targets 8.0).
     *
     * @param  array<int|string, mixed>  $value
     */
    private static function is_list(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
