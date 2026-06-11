<?php
/**
 * WP admin → Launchpad → "Slots & Shortcodes": a per-kit reference of every
 * bindable slot — the copyable shortcode, the scalar mirror key (for native Post
 * Custom Field binding), what it renders, the wrapper/item CSS classes (styling
 * targets), cardinality, and required-or-not. Reads the contract kit definitions
 * stored from the engine push, so it reflects the CONTRACT, not observed values
 * (no live values rendered — those vary per page; the contract doesn't).
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Admin;

use Launchpad\Companion\Meta;
use Launchpad\Companion\Render\ShortcodeReference;

if (! defined('ABSPATH')) {
    exit;
}

final class SlotsScreen
{
    /**
     * The engine-owned SEO fields emitted into the document <head> on every
     * managed post/page (see Seo\Head) — listed so an operator knows what is
     * emitted and does NOT add a competing SEO plugin. There is no template tag
     * to place: these bind automatically.
     */
    private const SEO_FIELDS = [
        'title' => 'Document <title>, og:title, twitter:title (also the post title).',
        'meta_description' => 'meta name="description", og:description, twitter:description.',
        'canonical' => 'link rel="canonical".',
        'robots' => 'robots meta — index/follow, honoring noindex / nofollow.',
        'og:image' => 'og:image / twitter:image — the rendered hero image (R2/CDN url).',
        'og:type, og:url' => 'OpenGraph type + URL.',
        'twitter:card' => 'summary_large_image when an image is present, else summary.',
    ];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void
    {
        add_menu_page('Launchpad', 'Launchpad', 'manage_options', 'launchpad-slots', [$this, 'render'], 'dashicons-screenoptions', 58);
        add_submenu_page('launchpad-slots', 'Slots & Shortcodes', 'Slots & Shortcodes', 'manage_options', 'launchpad-slots', [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $defs = get_option(Meta::OPTION_KIT_DEFINITIONS, []);
        $defs = is_array($defs) ? $defs : [];

        echo '<div class="wrap"><h1>Slots &amp; Shortcodes</h1>';
        echo '<p>Bind pushed content in a Theme Builder template. Place a <strong>shortcode</strong> (Shortcode/Text widget) for any slot; scalar slots can also bind via the native <strong>Post Custom Field</strong> tag using the mirror key. Definitions reflect the contract — live values vary per page.</p>';

        // Built-in Posts reference — the most-used binding (the post body) and the
        // engine-owned SEO fields. Always shown: posts carry no kit, so this never
        // appears in the kit-definition pushes below.
        $this->render_posts_reference();

        echo '<h2 style="margin-top:2.5em">Kit pages</h2>';

        if ($defs === []) {
            echo '<p><em>No kits have been pushed yet. Publish a page and this fills in.</em></p>';
        } else {
            foreach ($defs as $def) {
                $this->render_kit(is_array($def) ? $def : []);
            }
        }

        $this->copy_script();
        echo '</div>';
    }

    /**
     * The built-in Posts section: how a news/reactive post's article body binds
     * (shortcode + mirror key) and the SEO fields the engine emits — neither of
     * which rides a kit push, so the kit tables below never document them.
     */
    private function render_posts_reference(): void
    {
        $body = ShortcodeReference::for_type('rich_text', 'body');
        $mirror = ShortcodeReference::mirror_key('body');

        echo '<h2 style="margin-top:1.5em">Posts <span style="color:#888;font-weight:400">built-in</span></h2>';
        echo '<p>Every news / reactive <strong>post</strong> carries its article in the <code>body</code> slot — a scalar, so it binds two ways in the single-post Theme Builder template. Bind it once in the post template and every post fills it.</p>';

        echo '<table class="widefat striped" style="max-width:64em"><thead><tr>'
            . '<th>Slot</th><th>Shortcode</th><th>Post Custom Field</th><th>Renders</th>'
            . '</tr></thead><tbody><tr>';
        echo '<td><strong>Post body</strong><br><code>body</code><br><span style="color:#888">rich_text</span></td>';
        echo '<td>' . $this->copyable($body['shortcode']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copyable()
        echo '<td>' . $this->copyable($mirror) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copyable()
        echo '<td>The article HTML.</td>';
        echo '</tr></tbody></table>';

        echo '<h3 style="margin-top:1.5em">SEO fields <span style="color:#888;font-weight:400">auto-emitted — no binding needed</span></h3>';
        echo '<p>Launchpad owns SEO and prints it into the page <code>&lt;head&gt;</code> on every managed post and page. There is no template tag to place — these are listed so you know what is emitted (and do not add a competing SEO plugin).</p>';
        echo '<table class="widefat striped" style="max-width:64em"><thead><tr><th>Field</th><th>Where it appears</th></tr></thead><tbody>';
        foreach (self::SEO_FIELDS as $field => $where) {
            printf('<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html((string) $field), esc_html($where));
        }
        echo '</tbody></table>';
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function render_kit(array $def): void
    {
        $kit = (string) ($def['kit'] ?? '');
        $version = (string) ($def['version'] ?? '');
        $slots = is_array($def['slots'] ?? null) ? $def['slots'] : [];
        if ($kit === '' || $slots === []) {
            return;
        }

        printf('<h2 style="margin-top:2em">%s <span style="color:#888;font-weight:400">v%s</span></h2>', esc_html($kit), esc_html($version));
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Slot</th><th>Shortcode</th><th>Post Custom Field</th><th>Renders</th><th>Styling classes</th><th>Cardinality</th><th>Required</th>'
            . '</tr></thead><tbody>';

        foreach ($slots as $slot) {
            $this->render_row(is_array($slot) ? $slot : []);
        }

        echo '</tbody></table>';
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function render_row(array $slot): void
    {
        $key = (string) ($slot['key'] ?? '');
        if ($key === '') {
            return;
        }

        $type = (string) ($slot['content_type'] ?? '');
        $ref = ShortcodeReference::for_type($type, $key);

        echo '<tr>';
        printf('<td><strong>%s</strong><br><code>%s</code><br><span style="color:#888">%s</span></td>', esc_html((string) ($slot['label'] ?? $key)), esc_html($key), esc_html($type));
        echo '<td>' . $this->copyable($ref['shortcode']) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copyable()
        echo '<td>' . ($ref['scalar'] ? $this->copyable(ShortcodeReference::mirror_key($key)) : '<span style="color:#bbb">—</span>') . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copyable()
        printf('<td>%s</td>', esc_html($ref['renders']));
        printf('<td><code>%s</code></td>', esc_html($ref['classes']));
        printf('<td>%s</td>', esc_html($this->cardinality(is_array($slot['cardinality'] ?? null) ? $slot['cardinality'] : [])));
        printf('<td>%s</td>', ! empty($slot['required']) ? 'Yes' : 'No');
        echo '</tr>';
    }

    private function copyable(string $code): string
    {
        return sprintf(
            '<code>%s</code> <button type="button" class="button button-small lp-copy" data-clip="%s">Copy</button>',
            esc_html($code),
            esc_attr($code)
        );
    }

    /**
     * @param  array<string, mixed>  $cardinality
     */
    private function cardinality(array $cardinality): string
    {
        if (($cardinality['type'] ?? 'single') !== 'repeater') {
            return 'single';
        }

        $min = $cardinality['min'] ?? null;
        $max = $cardinality['max'] ?? null;

        return 'repeater ' . ($min ?? '0') . '..' . ($max ?? '∞');
    }

    private function copy_script(): void
    {
        echo '<script>document.querySelectorAll(".lp-copy").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard.writeText(b.dataset.clip);var t=b.textContent;b.textContent="Copied";setTimeout(function(){b.textContent=t;},1200);});});</script>';
    }
}
