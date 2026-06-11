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
        echo '<p>Bind pushed content in a Theme Builder template. Place a <strong>shortcode</strong> (Shortcode/Text widget) for any slot; scalar slots can also bind via the native <strong>Post Custom Field</strong> tag using the mirror key. Definitions reflect the kit contract — live values vary per page.</p>';

        if ($defs === []) {
            echo '<p><em>No kits have been pushed yet. Publish a page and this fills in.</em></p></div>';

            return;
        }

        foreach ($defs as $def) {
            $this->render_kit(is_array($def) ? $def : []);
        }

        $this->copy_script();
        echo '</div>';
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
