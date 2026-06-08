<?php
/**
 * Ensures a hierarchical WordPress category mirrors a control-plane Silo,
 * upserting on silo_id and returning the mapped wp_category_id.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Content;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class SiloStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{silo_id: string, wp_category_id: int}
     */
    public function ensure(array $payload): array
    {
        $silo_id = (string) ($payload['silo_id'] ?? '');
        $name = (string) ($payload['name'] ?? $silo_id);
        $parent_silo = isset($payload['parent_silo_id']) ? (string) $payload['parent_silo_id'] : null;

        $map = self::map();
        $parent_term = $parent_silo !== null ? (int) ($map[$parent_silo] ?? 0) : 0;

        $existing = isset($map[$silo_id]) ? (int) $map[$silo_id] : 0;

        if ($existing > 0 && term_exists($existing, 'category')) {
            wp_update_term($existing, 'category', [
                'name' => $name,
                'parent' => $parent_term,
            ]);
            $term_id = $existing;
        } else {
            $result = wp_insert_term($name, 'category', ['parent' => $parent_term]);

            if (is_wp_error($result)) {
                // Name collision: reuse the existing term.
                $term = get_term_by('name', $name, 'category');
                $term_id = $term ? (int) $term->term_id : 0;
            } else {
                $term_id = (int) $result['term_id'];
            }

            $map[$silo_id] = $term_id;
            update_option(Meta::OPTION_SILOS, $map, false);
        }

        return ['silo_id' => $silo_id, 'wp_category_id' => $term_id];
    }

    public static function term_for(string $silo_id): ?int
    {
        $map = self::map();

        return isset($map[$silo_id]) ? (int) $map[$silo_id] : null;
    }

    /**
     * @return array<string, int>
     */
    private static function map(): array
    {
        $map = get_option(Meta::OPTION_SILOS, []);

        return is_array($map) ? $map : [];
    }
}
