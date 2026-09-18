<?php
/**
 * "Areas we serve" map, drawn server-side as inline SVG from the geometry the control plane pushes.
 *
 * It used to be Leaflet over CARTO's basemap tiles. CARTO now requires an API key for those tiles, so
 * every live map rendered stamped API KEY REQUIRED across a customer's page. The replacement is not
 * another tile vendor: the counties and towns are OUR geometry (Census TIGERweb, pushed on the meta
 * blob), so the map is drawn from it directly.
 *
 * That removes a 147KB script, a stylesheet, a tile CDN and a key from the critical path of every page
 * that has a map, and puts the colours under the brand's own tokens. What it gives up is the basemap
 * underneath — roads and neighbouring labels — which an "areas we serve" figure does not need.
 *
 * Rendered into the empty `.lp-areas-map` mount the control plane already emits in post_content, so
 * every page published before this renders the new map on its next request, with no re-publish.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class AreaMap
{
    /** The drawing surface. Fixed aspect; the CSS scales it to the container. */
    private const W = 1000;

    private const H = 700;

    private const PAD = 28;

    public function register(): void
    {
        add_filter('the_content', [$this, 'inject'], 9);
    }

    /** Swap the server-side mount for the drawn map; leave the content alone when there is no geometry. */
    public function inject(string $content): string
    {
        if (! is_singular() || ! str_contains($content, 'lp-areas-map')) {
            return $content;
        }

        $map = get_post_meta(get_queried_object_id(), Meta::AREA_MAP, true);
        $svg = is_array($map) ? self::svg($map) : '';
        if ($svg === '') {
            return $content;   // no geometry → the mount stays empty and the town list carries the section
        }

        // The mount is an empty div with a role/aria-label; replace the whole element, attributes and all.
        return (string) preg_replace(
            '#<div class="lp-areas-map"[^>]*>\s*</div>#i',
            '<div class="lp-areas-map lp-areas-map--live">'.$svg.'</div>',
            $content,
            1,
        );
    }

    /**
     * The map itself. Pure: geometry in, SVG out, no WordPress and no network — so it is testable, and a
     * malformed payload yields an empty string rather than a broken figure.
     *
     * @param  array<string, mixed>  $map
     */
    public static function svg(array $map): string
    {
        $counties = is_array($map['counties'] ?? null) ? $map['counties'] : [];
        $cities = is_array($map['cities'] ?? null) ? $map['cities'] : [];
        $pin = is_array($map['pin'] ?? null) ? $map['pin'] : null;

        $points = [];
        foreach ($counties as $county) {
            foreach ((array) (is_array($county) ? ($county['rings'] ?? []) : []) as $ring) {
                foreach ((array) $ring as $point) {
                    if (is_array($point) && isset($point['lat'], $point['lng'])) {
                        $points[] = [(float) $point['lat'], (float) $point['lng']];
                    }
                }
            }
        }
        foreach ($cities as $city) {
            if (is_array($city) && isset($city['lat'], $city['lng'])) {
                $points[] = [(float) $city['lat'], (float) $city['lng']];
            }
        }
        if ($pin !== null && isset($pin['lat'], $pin['lng'])) {
            $points[] = [(float) $pin['lat'], (float) $pin['lng']];
        }
        if (count($points) < 2) {
            return '';   // a single point is a dot on a blank field, not a map
        }

        $project = self::projector($points);

        $body = '';
        foreach ($counties as $county) {
            $path = self::path(is_array($county) ? ($county['rings'] ?? []) : [], $project);
            if ($path === '') {
                continue;
            }
            $name = trim((string) (is_array($county) ? ($county['name'] ?? '') : ''));
            $body .= '<path d="'.esc_attr($path).'" class="lp-map-county">'
                .($name !== '' ? '<title>'.esc_html($name).'</title>' : '').'</path>';
        }

        foreach ($cities as $city) {
            if (! is_array($city) || ! isset($city['lat'], $city['lng'])) {
                continue;
            }
            [$x, $y] = $project((float) $city['lat'], (float) $city['lng']);
            $tier = (string) ($city['tier'] ?? '');
            $r = match ($tier) {
                'major' => 7.0,
                'large' => 6.0,
                'medium' => 5.0,
                default => 4.0,
            };
            $name = trim((string) ($city['name'] ?? ''));
            $dot = '<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="'.self::n($r).'" class="lp-map-town">'
                .($name !== '' ? '<title>'.esc_html($name).'</title>' : '').'</circle>';

            // A town with a page is a link — the click-through the Leaflet version carried, kept, and now
            // crawlable rather than bound to a JS handler.
            $url = trim((string) ($city['url'] ?? ''));
            $body .= $url !== ''
                ? '<a href="'.esc_url($url).'" class="lp-map-link">'.$dot.'</a>'
                : $dot;
        }

        if ($pin !== null && isset($pin['lat'], $pin['lng'])) {
            [$x, $y] = $project((float) $pin['lat'], (float) $pin['lng']);
            $label = trim((string) ($pin['label'] ?? ''));
            $body .= '<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="10" class="lp-map-pin">'
                .($label !== '' ? '<title>'.esc_html($label).'</title>' : '').'</circle>';
        }

        return '<svg class="lp-map-svg" viewBox="0 0 '.self::W.' '.self::H.'" role="img" '
            .'aria-label="Map of the areas we serve" preserveAspectRatio="xMidYMid meet" focusable="false">'
            .$body.'</svg>';
    }

    /**
     * An equirectangular projector fitted to the points, longitude scaled by cos(mean latitude) so the
     * shapes are not stretched east-west, and y flipped so north is up.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return callable(float, float): array{0: float, 1: float}
     */
    private static function projector(array $points): callable
    {
        $lats = array_column($points, 0);
        $lngs = array_column($points, 1);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);
        $k = max(0.1, cos(deg2rad(($minLat + $maxLat) / 2)));

        $spanX = max(1e-9, ($maxLng - $minLng) * $k);
        $spanY = max(1e-9, $maxLat - $minLat);
        $scale = min((self::W - 2 * self::PAD) / $spanX, (self::H - 2 * self::PAD) / $spanY);

        // Centre whatever is left over, so a wide county does not sit against the left edge.
        $offsetX = (self::W - $spanX * $scale) / 2;
        $offsetY = (self::H - $spanY * $scale) / 2;

        return static fn (float $lat, float $lng): array => [
            $offsetX + ($lng - $minLng) * $k * $scale,
            $offsetY + ($maxLat - $lat) * $scale,
        ];
    }

    /**
     * @param  mixed  $rings
     * @param  callable(float, float): array{0: float, 1: float}  $project
     */
    private static function path(mixed $rings, callable $project): string
    {
        $d = '';
        foreach ((array) $rings as $ring) {
            $first = true;
            foreach ((array) $ring as $point) {
                if (! is_array($point) || ! isset($point['lat'], $point['lng'])) {
                    continue;
                }
                [$x, $y] = $project((float) $point['lat'], (float) $point['lng']);
                $d .= ($first ? 'M' : 'L').self::n($x).' '.self::n($y);
                $first = false;
            }
            if (! $first) {
                $d .= 'Z';
            }
        }

        return $d;
    }

    /** Two decimals is a tenth of a pixel at this size — more is bytes nobody sees. */
    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
