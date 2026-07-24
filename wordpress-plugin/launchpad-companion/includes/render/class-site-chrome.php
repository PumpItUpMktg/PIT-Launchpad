<?php
/**
 * Renders the universal HEADER and FOOTER chrome from the pushed site profile ({@see
 * \Launchpad\Companion\Content\SiteProfileStore}). Exposed as the [lp_header] / [lp_footer] shortcodes
 * so the block theme's header/footer TEMPLATE PARTS stay thin — built once, site-wide — while the
 * per-tenant NAP + navigation stays data-driven. Semantic HTML with .lp-* classes; the block theme's
 * assets/theme.css owns the styling (tokens per active variation). Everything is escaped; a missing
 * profile degrades to the WordPress site title with no phone/nav rather than fataling.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Content\SiteProfileStore;

if (! defined('ABSPATH')) {
    exit;
}

final class SiteChrome
{
    public function register(): void
    {
        add_shortcode('lp_header', [$this, 'header']);
        add_shortcode('lp_footer', [$this, 'footer']);
    }

    public function header(): string
    {
        $p = SiteProfileStore::get();
        $home = esc_url(home_url('/'));
        $brand = $this->brandName($p);

        // The header background the uploaded logo is best shown on (control-plane LogoHeaderTone).
        // The theme styles the whole bar off this class (.lp-header:has(.lp-tone-dark)); default light.
        $tone = (isset($p['header_tone']) && $p['header_tone'] === 'dark') ? 'dark' : 'light';

        $out = '<div class="lp-header-inner lp-tone-' . $tone . '">';

        $out .= '<a class="lp-brand" href="' . $home . '">';
        // The uploaded logo (served from R2) replaces the text business name; no logo → text fallback.
        if (! empty($p['logo_url'])) {
            $out .= '<img class="lp-logo" src="' . esc_url((string) $p['logo_url']) . '" alt="' . esc_attr($brand) . '" />';
        } else {
            $out .= '<span class="lp-brand-name">' . esc_html($brand) . '</span>';
        }
        if (! empty($p['tagline'])) {
            $out .= '<span class="lp-brand-tag">' . esc_html((string) $p['tagline']) . '</span>';
        }
        $out .= '</a>';

        $out .= $this->navList($p['nav'] ?? [], 'lp-nav');
        $out .= $this->callbar($p);

        $out .= '</div>';

        // A slim secondary bar of the site's service pages, below the main menu — direct navigation to
        // services without cluttering the primary nav. Only when there are service pages. Inherits the
        // header tone so it reads on both a dark and a light bar.
        $services = $this->navList($p['services'] ?? [], 'lp-services-nav', true);
        if ($services !== '') {
            $out .= '<div class="lp-header-services lp-tone-' . $tone . '"><div class="lp-header-services-inner">'
                . '<span class="lp-services-label">Services</span>' . $services
                . '</div></div>';
        }

        return $out;
    }

    public function footer(): string
    {
        $p = SiteProfileStore::get();
        $brand = $this->brandName($p);

        $out = '<div class="lp-footer-inner"><div class="lp-footer-cols">';

        // Brand + NAP column.
        $out .= '<div class="lp-fcol lp-fbrand">';
        $out .= '<b>' . esc_html($brand) . '</b>';
        if (! empty($p['tagline'])) {
            $out .= '<p>' . esc_html((string) $p['tagline']) . '</p>';
        }
        if (! empty($p['phone']) && ! empty($p['phone_tel'])) {
            $out .= '<a class="lp-fphone" href="' . esc_url((string) $p['phone_tel']) . '">' . esc_html((string) $p['phone']) . '</a>';
        }
        if (! empty($p['hours'])) {
            $out .= '<p class="lp-fmeta">' . esc_html((string) $p['hours']) . '</p>';
        }
        if (! empty($p['address'])) {
            $out .= '<p class="lp-fmeta">' . esc_html((string) $p['address']) . '</p>';
        }
        $out .= '</div>';

        $out .= $this->footerColumn('Services', $p['services'] ?? []);
        // Service Areas (curated town list) is covered by the home page's areas map + grouped cities —
        // dropped from the footer to avoid a redundant county/town list.
        $out .= $this->footerColumn('Company', $p['company'] ?? []);

        $out .= '</div>';

        // Bottom bar.
        $legal = ! empty($p['legal']) ? (string) $p['legal'] : '© ' . esc_html(gmdate('Y')) . ' ' . $brand;
        $out .= '<div class="lp-footer-bot">';
        $out .= '<span>' . esc_html($legal) . '</span>';
        // Legal links (Privacy / Terms) — data-driven; only pages that exist reach the profile.
        if (! empty($p['legal_links']) && is_array($p['legal_links'])) {
            $out .= $this->navList($p['legal_links'], 'lp-flegal');
        }
        $out .= '<span>Standard WordPress blocks — no page builder, nothing locked in.</span>';
        $out .= '</div>';

        return $out . '</div>';
    }

    /**
     * The click-to-call bar. Emergency (opted-in) gets the pulsing 24/7 tag; a phone is required or the
     * bar is omitted entirely.
     *
     * @param  array<string, mixed>  $p
     */
    private function callbar(array $p): string
    {
        if (empty($p['phone']) || empty($p['phone_tel'])) {
            return '';
        }

        $emergency = ! empty($p['emergency']);
        $class = 'lp-callbar' . ($emergency ? ' is-emergency' : '');

        $out = '<a class="' . esc_attr($class) . '" href="' . esc_url((string) $p['phone_tel']) . '">';
        if ($emergency) {
            $out .= '<span class="lp-callbar-tag"><span class="lp-pulse" aria-hidden="true"></span> 24/7 Emergency</span>';
        }
        $out .= '<span class="lp-callbar-num">' . $this->phoneIcon() . esc_html((string) $p['phone']) . '</span>';

        return $out . '</a>';
    }

    /**
     * @param  list<array{label: string, url: string}>|mixed  $links
     */
    private function footerColumn(string $title, mixed $links): string
    {
        if (! is_array($links) || $links === []) {
            return '';
        }

        return '<div class="lp-fcol"><h5>' . esc_html($title) . '</h5>' . $this->navList($links, 'lp-fnav') . '</div>';
    }

    /**
     * A list of links (linked when a URL is present, plain text otherwise). When $nested is true, an
     * item carrying a `children` array renders as a dropdown: the parent link with a `.lp-subnav` of its
     * child links beneath it (the operator's service grouping — a hub with its spokes). $nested false
     * (footer, legal, main nav) ignores children and renders a flat list.
     *
     * @param  list<array{label: string, url: string, children?: array}>|mixed  $links
     */
    private function navList(mixed $links, string $class, bool $nested = false): string
    {
        if (! is_array($links) || $links === []) {
            return '';
        }

        $out = '<nav class="' . esc_attr($class) . '">';
        foreach ($links as $link) {
            if (! is_array($link) || empty($link['label'])) {
                continue;
            }
            $children = ( $nested && ! empty($link['children']) && is_array($link['children']) ) ? $link['children'] : array();

            if ($children !== array()) {
                $out .= '<span class="lp-nav-item lp-has-sub">' . $this->navLink($link)
                    . '<span class="lp-subnav">';
                foreach ($children as $child) {
                    if (is_array($child) && ! empty($child['label'])) {
                        $out .= $this->navLink($child);
                    }
                }
                $out .= '</span></span>';

                continue;
            }

            $out .= $this->navLink($link);
        }

        return $out . '</nav>';
    }

    /**
     * One nav link — an anchor when a URL is present, plain text otherwise.
     *
     * @param  array{label?: mixed, url?: mixed}  $link
     */
    private function navLink(array $link): string
    {
        $label = esc_html((string) $link['label']);
        $url = ! empty($link['url']) ? esc_url((string) $link['url']) : '';

        return $url !== '' ? '<a href="' . $url . '">' . $label . '</a>' : '<span>' . $label . '</span>';
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function brandName(array $p): string
    {
        $brand = trim((string) ($p['brand_name'] ?? ''));

        return $brand !== '' ? $brand : (string) get_bloginfo('name');
    }

    private function phoneIcon(): string
    {
        return '<svg class="lp-phone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg>';
    }
}
