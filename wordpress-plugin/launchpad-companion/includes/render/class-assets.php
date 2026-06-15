<?php
/**
 * Front-end assets: the baseline design stylesheets.
 *
 *  - assets/launchpad.css  — the .lp-* render-block layer (dynamic-tag / post path).
 *  - assets/wireframe.css  — the base wf-* layer for NATIVE library pages, parameterized
 *    entirely by CSS custom properties (structure tokens + brand tokens).
 *
 * The per-tenant brand tokens are printed as a `:root { --wf-* }` inline block from the
 * `lp_brand_tokens` option (written by push-brand-kit), so brand survives republish and
 * needs no Elementor-Pro custom CSS. The chosen structure preset is delivered as a
 * `body.wf-structure-{slug}` class (see TemplateRouter); the structure token bundles
 * live in wireframe.css. Both stylesheets are brand-neutral and class-scoped, so
 * enqueuing site-wide is harmless.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class Assets
{
    public const HANDLE = 'launchpad-baseline';

    public const WF_HANDLE = 'launchpad-wireframe';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        wp_enqueue_style(self::HANDLE, LPC_URL . 'assets/launchpad.css', [], LPC_VERSION);
        wp_enqueue_style(self::WF_HANDLE, LPC_URL . 'assets/wireframe.css', [], LPC_VERSION);

        $this->enqueue_brand_fonts();

        $root = $this->brand_root_block();
        if ($root !== '') {
            wp_add_inline_style(self::WF_HANDLE, $root);
        }
    }

    private function brand_root_block(): string
    {
        $tokens = get_option(Meta::OPTION_BRAND_TOKENS, []);

        return self::root_block(is_array($tokens) ? $tokens : []);
    }

    /**
     * The per-tenant `:root { --wf-* }` block from a brand-token map. Only recognized
     * `--wf-*` custom-property names are emitted, and every value is sanitized to a
     * conservative charset (no `;{}<>` — no CSS breakout), so a bad option can never
     * inject CSS. Pure + static so it is unit-testable without the enqueue machinery.
     *
     * @param array<string,mixed> $tokens
     */
    public static function root_block(array $tokens): string
    {
        $decls = '';
        foreach ($tokens as $name => $value) {
            if (! is_string($name) || ! preg_match('/^--wf-[a-z0-9-]+$/', $name)) {
                continue;
            }
            $clean = self::sanitize_value((string) $value);
            if ($clean !== '') {
                $decls .= $name . ':' . $clean . ';';
            }
        }

        return $decls === '' ? '' : ':root{' . $decls . '}';
    }

    /**
     * A CSS value safe to inline: a conservative charset (hex/rgb/keywords/font
     * names/units), `;{}<>` stripped, length-capped.
     */
    private static function sanitize_value(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9 #,.%()\-_\'"]/', '', trim($value)) ?? '';

        return substr($value, 0, 120);
    }

    /**
     * Load the tenant's heading/body Google Fonts so the --wf-font-* tokens actually
     * render on native pages (Elementor only auto-loads fonts it knows from the kit).
     */
    private function enqueue_brand_fonts(): void
    {
        $tokens = get_option(Meta::OPTION_BRAND_TOKENS, []);
        if (! is_array($tokens)) {
            return;
        }

        $families = [];
        foreach (['--wf-font-heading', '--wf-font-body'] as $key) {
            $family = isset($tokens[$key]) ? trim((string) $tokens[$key]) : '';
            // Skip empties + the `inherit` fallback (not a loadable family).
            if ($family !== '' && strtolower($family) !== 'inherit' && preg_match('/^[A-Za-z0-9 ]+$/', $family)) {
                $families[$family] = $family;
            }
        }

        if ($families === []) {
            return;
        }

        $parts = [];
        foreach ($families as $family) {
            $parts[] = 'family=' . str_replace(' ', '+', $family) . ':wght@400;600;700;800';
        }
        $url = 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';

        wp_enqueue_style('launchpad-brand-fonts', $url, [], null);
    }
}
