<?php
/**
 * Shared slot-key control + value lookup for the lp/* dynamic tags. Reads from
 * the request-cached payload of the current managed post.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render\DynamicTags;

use Elementor\Controls_Manager;
use Launchpad\Companion\Render\Payload;

if (! defined('ABSPATH')) {
    exit;
}

trait SlotControl
{
    protected function register_slot_control(): void
    {
        $this->add_control('slot', [
            'label' => 'Slot key',
            'type' => Controls_Manager::TEXT,
            'default' => '',
        ]);
    }

    protected function slot_key(): string
    {
        return (string) $this->get_settings('slot');
    }

    protected function slot_value(): mixed
    {
        return Payload::slot(Payload::current_id(), $this->slot_key());
    }
}
