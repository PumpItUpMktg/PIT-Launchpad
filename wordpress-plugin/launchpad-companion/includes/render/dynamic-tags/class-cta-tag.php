<?php
/**
 * lp/cta — renders a call-to-action anchor from a {label, url} slot.
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

class CtaTag extends Tag
{
    use SlotControl;

    public function get_name(): string
    {
        return 'lp-cta';
    }

    public function get_title(): string
    {
        return 'LP CTA';
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
        echo SlotRenderer::cta($this->slot_value()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by SlotRenderer
    }
}
