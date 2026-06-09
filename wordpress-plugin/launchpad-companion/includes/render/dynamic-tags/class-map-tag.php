<?php
/**
 * lp/map — renders a lazy-loaded map embed from {embed_url} or {lat,lng}.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render\DynamicTags;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

if (! defined('ABSPATH')) {
    exit;
}

class MapTag extends Tag
{
    use SlotControl;

    public function get_name(): string
    {
        return 'lp-map';
    }

    public function get_title(): string
    {
        return 'LP Map';
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

        if (! is_array($value)) {
            return;
        }

        if (! empty($value['embed_url'])) {
            $src = (string) $value['embed_url'];
        } elseif (isset($value['lat'], $value['lng'])) {
            $src = 'https://www.google.com/maps?q=' . rawurlencode($value['lat'] . ',' . $value['lng']) . '&output=embed';
        } else {
            return;
        }

        printf(
            '<iframe class="lp-map" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="%s"></iframe>',
            esc_url($src)
        );
    }
}
