<?php
/**
 * Registers the lp/* custom dynamic tags with Elementor (modern register API,
 * with a fallback for older Elementor versions).
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Render\DynamicTags\CtaTag;
use Launchpad\Companion\Render\DynamicTags\ImageTag;
use Launchpad\Companion\Render\DynamicTags\MapTag;
use Launchpad\Companion\Render\DynamicTags\RepeaterTag;
use Launchpad\Companion\Render\DynamicTags\TextTag;

if (! defined('ABSPATH')) {
    exit;
}

final class TagManager
{
    private const TAGS = [TextTag::class, ImageTag::class, CtaTag::class, MapTag::class, RepeaterTag::class];

    public function register(mixed $dynamic_tags): void
    {
        if (method_exists($dynamic_tags, 'register_group')) {
            $dynamic_tags->register_group('launchpad', ['title' => 'Launchpad']);
        }

        foreach (self::TAGS as $tag) {
            if (method_exists($dynamic_tags, 'register')) {
                $dynamic_tags->register(new $tag());
            } elseif (method_exists($dynamic_tags, 'register_tag')) {
                $dynamic_tags->register_tag($tag);
            }
        }
    }
}
