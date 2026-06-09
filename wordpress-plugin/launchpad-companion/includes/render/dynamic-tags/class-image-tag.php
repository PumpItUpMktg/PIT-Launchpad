<?php
/**
 * lp/image — returns the R2/CDN URL for an image slot (served directly, never
 * imported into the media library).
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render\DynamicTags;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;
use Launchpad\Companion\Render\Payload;

if (! defined('ABSPATH')) {
    exit;
}

class ImageTag extends Data_Tag
{
    use SlotControl;

    public function get_name(): string
    {
        return 'lp-image';
    }

    public function get_title(): string
    {
        return 'LP Image';
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
        return [Module::IMAGE_CATEGORY];
    }

    protected function register_controls(): void
    {
        $this->register_slot_control();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function get_value(array $options = []): array
    {
        $image = Payload::image(Payload::current_id(), $this->slot_key());

        // Prefer the sideloaded local attachment (id drives Elementor's srcset /
        // image handling); fall back to the R2 url if the sideload didn't run.
        $id = is_array($image) && ! empty($image['attachment_id']) ? (int) $image['attachment_id'] : 0;
        $url = is_array($image) && ! empty($image['url']) ? (string) $image['url'] : '';

        return ['id' => $id, 'url' => $url];
    }
}
