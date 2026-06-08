<?php
/**
 * Request-cached accessor for a managed post's consolidated slot blob, image
 * map, and SEO map. The meta is read once per post per request.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class Payload
{
    /** @var array<int, array<string, mixed>> */
    private static array $slots = [];

    /** @var array<int, array<string, mixed>> */
    private static array $images = [];

    /** @var array<int, array<string, mixed>> */
    private static array $seo = [];

    /**
     * @return array<string, mixed>
     */
    public static function slots(int $post_id): array
    {
        if (! isset(self::$slots[$post_id])) {
            $value = get_post_meta($post_id, Meta::SLOTS, true);
            self::$slots[$post_id] = is_array($value) ? $value : [];
        }

        return self::$slots[$post_id];
    }

    public static function slot(int $post_id, string $key): mixed
    {
        return self::slots($post_id)[$key] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function image(int $post_id, string $key): ?array
    {
        if (! isset(self::$images[$post_id])) {
            $value = get_post_meta($post_id, Meta::IMAGES, true);
            self::$images[$post_id] = is_array($value) ? $value : [];
        }

        $image = self::$images[$post_id][$key] ?? null;

        return is_array($image) ? $image : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function seo(int $post_id): array
    {
        if (! isset(self::$seo[$post_id])) {
            $value = get_post_meta($post_id, Meta::SEO, true);
            self::$seo[$post_id] = is_array($value) ? $value : [];
        }

        return self::$seo[$post_id];
    }

    public static function is_managed(int $post_id): bool
    {
        return $post_id > 0 && get_post_meta($post_id, Meta::CONTENT_ID, true) !== '';
    }

    public static function current_id(): int
    {
        $id = get_queried_object_id();

        return $id > 0 ? (int) $id : (int) get_the_ID();
    }
}
