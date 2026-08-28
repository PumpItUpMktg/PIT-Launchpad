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

        // Mobile menu toggle — a CSS-only checkbox + hamburger label (the theme hides both on desktop,
        // shows the hamburger and drawers the nav on small screens). No JS, so it works even with the
        // script-delay optimizer active. Sibling of .lp-nav so `:checked ~ .lp-nav` reveals it.
        if (! empty($p['nav'])) {
            $out .= '<input type="checkbox" id="lp-nav-toggle" class="lp-nav-checkbox">';
            $out .= '<label for="lp-nav-toggle" class="lp-hamburger" aria-label="Menu"><span></span><span></span><span></span></label>';
        }

        $out .= $this->navList($p['nav'] ?? [], 'lp-nav');
        $out .= $this->callbar($p);

        $out .= '</div>';

        // A slim secondary bar of the site's service pages, below the main menu — direct navigation to
        // services without cluttering the primary nav. Only when there are service pages. Inherits the
        // header tone so it reads on both a dark and a light bar.
        $services = $this->servicesMenu($p);
        if ($services !== '') {
            $out .= '<div class="lp-header-services lp-tone-' . $tone . '"><div class="lp-header-services-inner">'
                . '<span class="lp-services-label">Services</span>' . $services
                . '</div></div>';
        }

        return $out;
    }

    /**
     * The header services menu (grouped nav). A hub is a group — a heading that LINKS to the hub page,
     * with its spokes as a dropdown; a standalone service is a plain link. Labels use the short `nav_label`.
     * The rendering MODE is chosen by count from the pushed `nav_menu` thresholds and carried as a class on
     * the <nav> for the theme to style: a flat row up to `flat_max` total links, a grouped mega-menu above
     * it, and top-level groups beyond `group_overflow` folded into a trailing "More" group. Markup keeps the
     * existing hover-dropdown structure (`lp-has-sub` / `lp-subnav`), so it degrades to today's behavior; the
     * click-to-open + mobile-drawer interaction layer (theme CSS + JS) rides on these classes next.
     *
     * @param  array<string, mixed>  $p
     */
    private function servicesMenu(array $p): string
    {
        $services = array_values(array_filter(
            is_array($p['services'] ?? null) ? $p['services'] : [],
            fn ($s): bool => is_array($s) && ! empty($s['label']),
        ));
        if ($services === []) {
            return '';
        }

        $cfg = is_array($p['nav_menu'] ?? null) ? $p['nav_menu'] : [];
        $flatMax = max(1, (int) ($cfg['flat_max'] ?? 6));
        $overflow = max(1, (int) ($cfg['group_overflow'] ?? 8));

        // Total links = top-level items + all their spokes; a mega-menu once past flat_max.
        $total = 0;
        foreach ($services as $s) {
            $total += 1 + count($this->childrenOf($s));
        }
        $mode = $total > $flatMax ? 'lp-services-nav--mega' : 'lp-services-nav--flat';

        $out = '<nav class="lp-services-nav ' . $mode . '" aria-label="Services">';

        $groupsShown = 0;
        $overflowGroups = [];
        foreach ($services as $s) {
            $kids = $this->childrenOf($s);
            if ($kids !== [] && $groupsShown >= $overflow) {
                $overflowGroups[] = $s;   // fold into the trailing "More" group
                continue;
            }
            $out .= $this->serviceItem($s, $kids);
            if ($kids !== []) {
                $groupsShown++;
            }
        }
        if ($overflowGroups !== []) {
            // "More" collapses the overflow groups' hub links into one dropdown.
            $moreKids = array_map(fn (array $s): array => [
                'label' => $s['label'] ?? '', 'url' => $s['url'] ?? '', 'nav_label' => $s['nav_label'] ?? '',
            ], $overflowGroups);
            $out .= $this->serviceItem(['label' => 'More', 'url' => ''], $moreKids);
        }

        return $out . '</nav>';
    }

    /**
     * One services-menu item: a plain link when it has no spokes, else a group — a heading (a clickable
     * link to the hub, or plain text for the synthetic "More") with its spokes in a `lp-subnav` dropdown.
     * Short `nav_label`s throughout. Same structure the theme already reveals on hover/focus.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $kids
     */
    private function serviceItem(array $item, array $kids): string
    {
        if ($kids === []) {
            return '<span class="lp-nav-item">' . $this->navLink($item, true) . '</span>';
        }

        // The heading links to the hub when it has a URL (spec: headings are clickable, not inert labels);
        // the synthetic "More" has none, so it's plain text.
        $heading = ! empty($item['url'])
            ? $this->navLink($item, true)
            : '<span class="lp-nav-head">' . esc_html($this->linkText($item, true)) . '</span>';

        $out = '<span class="lp-nav-item lp-has-sub">' . $heading . '<span class="lp-subnav">';
        foreach ($kids as $child) {
            if (is_array($child) && ! empty($child['label'])) {
                $out .= $this->navLink($child, true);
            }
        }

        return $out . '</span></span>';
    }

    /**
     * A service item's spoke links (non-empty children), or [].
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function childrenOf(array $item): array
    {
        if (empty($item['children']) || ! is_array($item['children'])) {
            return [];
        }

        return array_values(array_filter(
            $item['children'],
            fn ($c): bool => is_array($c) && ! empty($c['label']),
        ));
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

        // The footer services column is LONG-FORM on purpose: it renders each service's full `label`
        // (title), NOT the short header `nav_label`. The header trades keyword-rich anchor text for a
        // clean menu; the footer keeps that internal-linking anchor text. They are intentionally
        // different — do NOT "unify" the two to share one label. footerColumn → navList → navLink(false)
        // keeps this (the header services menu is the only surface that passes short=true).
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
        // Agency credit. Filterable so a site can white-label or remove it (return '' to drop the
        // line); wp_kses_post lets the default carry a link back to the agency.
        $credit = apply_filters(
            'launchpad_footer_credit',
            'Site designed &amp; managed by Pump It Up Marketing'
        );
        if (! empty($credit)) {
            $out .= '<span class="lp-credit">' . wp_kses_post((string) $credit) . '</span>';
        }
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

        // h2 (not h5): footer columns are the top-level sections of the site-info landmark. h5 skipped
        // levels after the page's content headings (a WCAG heading-order flag). The .lp-fcol-title class
        // carries the styling so the visual size is independent of the heading level.
        return '<div class="lp-fcol"><h2 class="lp-fcol-title">' . esc_html($title) . '</h2>' . $this->navList($links, 'lp-fnav') . '</div>';
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
     * One nav link — an anchor when a URL is present, plain text otherwise. When $short is true the header's
     * short `nav_label` is preferred over the full title (grouped nav: short menu label, long-form footer);
     * the footer + main nav pass false and keep the full title.
     *
     * @param  array{label?: mixed, url?: mixed, nav_label?: mixed}  $link
     */
    private function navLink(array $link, bool $short = false): string
    {
        $label = esc_html($this->linkText($link, $short));
        $url = ! empty($link['url']) ? esc_url((string) $link['url']) : '';

        return $url !== '' ? '<a href="' . $url . '">' . $label . '</a>' : '<span>' . $label . '</span>';
    }

    /**
     * The display text for a link: the short `nav_label` when asked (and present), else the full `label`.
     *
     * @param  array{label?: mixed, nav_label?: mixed}  $link
     */
    private function linkText(array $link, bool $short = false): string
    {
        if ($short && ! empty($link['nav_label'])) {
            return (string) $link['nav_label'];
        }

        return (string) ($link['label'] ?? '');
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
