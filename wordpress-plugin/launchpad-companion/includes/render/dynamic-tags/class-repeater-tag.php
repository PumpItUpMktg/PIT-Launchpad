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
use Launchpad\Companion\Render\SlotRenderer;

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
        echo SlotRenderer::repeater($this->slot_key(), $this->slot_value()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by SlotRenderer
    }
}
