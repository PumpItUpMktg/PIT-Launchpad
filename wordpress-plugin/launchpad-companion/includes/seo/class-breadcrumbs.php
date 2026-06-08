<?php
/**
 * Visible breadcrumbs from the payload's seo.breadcrumbs trail. Exposed as the
 * [lp_breadcrumbs] shortcode for placement in brand-neutral templates.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Seo;

use Launchpad\Companion\Render\Payload;

if (! defined('ABSPATH')) {
    exit;
}

final class Breadcrumbs
{
    /**
     * @return array<int, array{name: string, url: string}>
     */
    public static function trail(int $post_id): array
    {
        $crumbs = Payload::seo($post_id)['breadcrumbs'] ?? [];
        $trail = [];

        if (is_array($crumbs)) {
            foreach ($crumbs as $crumb) {
                if (is_array($crumb) && isset($crumb['name'])) {
                    $trail[] = [
                        'name' => (string) $crumb['name'],
                        'url' => (string) ($crumb['url'] ?? ''),
                    ];
                }
            }
        }

        return $trail;
    }

    public static function shortcode(): string
    {
        $id = Payload::current_id();
        $trail = self::trail($id);

        if ($trail === []) {
            return '';
        }

        $items = [];
        foreach ($trail as $crumb) {
            $items[] = $crumb['url'] !== ''
                ? sprintf('<a href="%s">%s</a>', esc_url($crumb['url']), esc_html($crumb['name']))
                : '<span aria-current="page">' . esc_html($crumb['name']) . '</span>';
        }

        return '<nav class="lp-breadcrumbs" aria-label="Breadcrumb">' . implode('<span class="lp-breadcrumbs__sep"> / </span>', $items) . '</nav>';
    }
}
