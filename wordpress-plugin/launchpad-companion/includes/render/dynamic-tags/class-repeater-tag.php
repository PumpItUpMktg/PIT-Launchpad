<?php
/**
 * lp/repeater — renders a repeater slot (list, faq, testimonials, stats, cta,
 * gallery) as markup, collapsing cleanly to nothing when the slot is empty.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

if (! defined('ABSPATH')) {
    exit;
}

class RepeaterTag extends Tag
{
    use SlotControl;

    public function get_name(): string
    {
        return 'lp-repeater';
    }

    public function get_title(): string
    {
        return 'LP Repeater';
    }

    public function get_group(): string
    {
        return 'launchpad';
    }

    /**
     * @return array<int, string>
     */
    public function get_categories(): array
    {
        return [Module::TEXT_CATEGORY];
    }

    protected function register_controls(): void
    {
        $this->register_slot_control();
    }

    public function render(): void
    {
        $items = $this->slot_value();

        // Collapse cleanly when empty.
        if (! is_array($items) || $items === []) {
            return;
        }

        echo '<div class="lp-repeater lp-repeater--' . esc_attr($this->slot_key()) . '">';

        foreach ($items as $item) {
            echo $this->render_item($item); // phpcs:ignore WordPress.Security.EscapeOutput
        }

        echo '</div>';
    }

    private function render_item(mixed $item): string
    {
        if (is_string($item)) {
            return '<div class="lp-repeater__item">' . esc_html($item) . '</div>';
        }

        if (! is_array($item)) {
            return '';
        }

        if (isset($item['question'], $item['answer'])) {
            return sprintf(
                '<div class="lp-faq"><h3 class="lp-faq__q">%s</h3><div class="lp-faq__a">%s</div></div>',
                esc_html((string) $item['question']),
                wp_kses_post((string) $item['answer'])
            );
        }

        if (isset($item['quote']) || isset($item['body'])) {
            return sprintf(
                '<figure class="lp-testimonial"><blockquote>%s</blockquote><figcaption>%s</figcaption></figure>',
                esc_html((string) ($item['quote'] ?? $item['body'])),
                esc_html((string) ($item['author'] ?? ''))
            );
        }

        if (isset($item['value'], $item['label'])) {
            return sprintf(
                '<div class="lp-stat"><span class="lp-stat__value">%s</span><span class="lp-stat__label">%s</span></div>',
                esc_html((string) $item['value']),
                esc_html((string) $item['label'])
            );
        }

        if (! empty($item['url']) && isset($item['label'])) {
            return sprintf(
                '<a class="lp-repeater__cta" href="%s">%s</a>',
                esc_url((string) $item['url']),
                esc_html((string) $item['label'])
            );
        }

        if (! empty($item['url'])) {
            return sprintf(
                '<img class="lp-repeater__img" src="%s" alt="%s" loading="lazy" decoding="async" />',
                esc_url((string) $item['url']),
                esc_attr((string) ($item['alt'] ?? ''))
            );
        }

        return '';
    }
}
