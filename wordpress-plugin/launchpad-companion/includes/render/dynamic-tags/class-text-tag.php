<?php
/**
 * lp/text — outputs a text slot value.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

if (! defined('ABSPATH')) {
    exit;
}

class TextTag extends Tag
{
    use SlotControl;

    public function get_name(): string
    {
        return 'lp-text';
    }

    public function get_title(): string
    {
        return 'LP Text';
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
        $value = $this->slot_value();

        if (is_string($value)) {
            echo wp_kses_post($value);
        }
    }
}
