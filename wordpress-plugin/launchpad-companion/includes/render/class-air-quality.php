<?php
/**
 * Local air-quality card — a LIVE, self-updating widget for any widget area, block or template.
 *
 * Rendered by the theme's "Local conditions" part (and by [lp_air_quality] anywhere else), but only when
 * the control plane says this tenant wants it: the part ships to every site, so `air.enabled` on the site
 * profile is the switch. A shortcode carrying its own lat/lng is a deliberate placement and renders
 * regardless. Coordinates are the ones the weather banner already uses, and this fetches Open-Meteo's
 * air-quality API ITSELF — free, no key, and verified to carry US AQI (the pollen fields in that API are
 * Europe-only and are deliberately not read here).
 *
 * Cached in a one-hour transient because the upstream index is hourly: a busy site makes one request an
 * hour, not one a visit. Every failure is quiet and renders nothing — a down API never breaks a page.
 *
 * The card states the reading and the hour it was taken, never "the air is good here" as a standing
 * claim: an index is a measurement with a timestamp, and the timestamp is part of the fact.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Content\SiteProfileStore;

if (! defined('ABSPATH')) {
    exit;
}

final class AirQuality
{
    private const ENDPOINT = 'https://air-quality-api.open-meteo.com/v1/air-quality';

    private const CACHE_PREFIX = 'lp_aq_';

    private const CACHE_SECONDS = 3600;

    public function register(): void
    {
        add_shortcode('lp_air_quality', [$this, 'shortcode']);
    }

    /**
     * [lp_air_quality] — optional lat/lng override, optional title.
     *
     * @param  array<string, string>|string  $atts
     */
    public function shortcode($atts = []): string
    {
        $atts = shortcode_atts(['lat' => '', 'lng' => '', 'title' => 'Local air quality'], is_array($atts) ? $atts : []);

        $profile = SiteProfileStore::get();
        $cfg = is_array($profile['air'] ?? null) ? $profile['air'] : [];
        $override = $atts['lat'] !== '' && $atts['lng'] !== '';

        // The theme carries this part on every site, so the tenant flag is what decides whether it renders
        // anything at all — an air-quality reading earns its place on an HVAC or mold site and is noise on
        // a sump-pump one. An explicit lat/lng in the shortcode is a deliberate placement and stands alone.
        if (! $override && empty($cfg['enabled'])) {
            return '';
        }

        $lat = $override ? (float) $atts['lat'] : (isset($cfg['lat']) ? (float) $cfg['lat'] : null);
        $lng = $override ? (float) $atts['lng'] : (isset($cfg['lng']) ? (float) $cfg['lng'] : null);
        if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
            return '';
        }

        $reading = $this->reading($lat, $lng);
        if ($reading === null) {
            return '';
        }

        return $this->markup($reading, (string) $atts['title']);
    }

    /**
     * Pure evaluation (unit-testable, no network): Open-Meteo's `current` block → the card's values.
     * A missing index is no card at all; a missing pollutant is simply omitted from the one that shows.
     *
     * @param  array<string, mixed>  $current
     * @return array{aqi: int, band: string, colour: string, pm25: float|null, ozone: float|null, taken: string}|null
     */
    public static function evaluate(array $current): ?array
    {
        if (! isset($current['us_aqi']) || ! is_numeric($current['us_aqi'])) {
            return null;
        }
        $aqi = (int) round((float) $current['us_aqi']);

        // The EPA's own bands and their published colours — not our reading of the number.
        [$band, $colour] = match (true) {
            $aqi <= 50 => ['Good', '#00e400'],
            $aqi <= 100 => ['Moderate', '#ffff00'],
            $aqi <= 150 => ['Unhealthy for sensitive groups', '#ff7e00'],
            $aqi <= 200 => ['Unhealthy', '#ff0000'],
            $aqi <= 300 => ['Very unhealthy', '#8f3f97'],
            default => ['Hazardous', '#7e0023'],
        };

        return [
            'aqi' => $aqi,
            'band' => $band,
            'colour' => $colour,
            'pm25' => isset($current['pm2_5']) && is_numeric($current['pm2_5']) ? round((float) $current['pm2_5'], 1) : null,
            'ozone' => isset($current['ozone']) && is_numeric($current['ozone']) ? round((float) $current['ozone'], 1) : null,
            'taken' => isset($current['time']) ? (string) $current['time'] : '',
        ];
    }

    /**
     * @return array{aqi: int, band: string, colour: string, pm25: float|null, ozone: float|null, taken: string}|null
     */
    private function reading(float $lat, float $lng): ?array
    {
        $key = self::CACHE_PREFIX . md5($lat . ',' . $lng);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached === [] ? null : $cached;   // [] is a cached failure, held briefly
        }

        $url = add_query_arg([
            'latitude' => $lat,
            'longitude' => $lng,
            'current' => 'us_aqi,pm2_5,ozone',
            'timezone' => 'auto',
        ], self::ENDPOINT);

        $response = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($key, [], 15 * MINUTE_IN_SECONDS);

            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $current = is_array($body) && isset($body['current']) && is_array($body['current']) ? $body['current'] : [];
        $reading = self::evaluate($current);

        set_transient($key, $reading ?? [], $reading === null ? 15 * MINUTE_IN_SECONDS : self::CACHE_SECONDS);

        return $reading;
    }

    /**
     * The card. Self-contained (inline styles, no enqueue) so it works in any widget area on any theme,
     * painted in the brand's surface roles where the block theme defines them.
     *
     * @param  array{aqi: int, band: string, colour: string, pm25: float|null, ozone: float|null, taken: string}  $r
     */
    private function markup(array $r, string $title): string
    {
        $style = '.lp-aq{border:1px solid var(--wp--preset--color--outline,#e2e7ee);border-radius:12px;padding:14px 16px;'
            . 'font:400 14px/1.45 system-ui,sans-serif;background:var(--wp--preset--color--surface,#fff);max-width:340px}'
            . '.lp-aq h4{margin:0 0 8px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;opacity:.7;font-weight:700}'
            . '.lp-aq .lp-aq-n{display:flex;align-items:baseline;gap:10px}'
            . '.lp-aq .lp-aq-v{font-size:34px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}'
            . '.lp-aq .lp-aq-b{font-weight:700}'
            . '.lp-aq .lp-aq-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}'
            . '.lp-aq .lp-aq-m{margin:8px 0 0;opacity:.75;font-size:12.5px}'
            . '.lp-aq .lp-aq-src{margin:6px 0 0;opacity:.6;font-size:11px}';

        $measures = [];
        if ($r['pm25'] !== null) {
            $measures[] = 'PM2.5 ' . esc_html(number_format($r['pm25'], 1)) . ' µg/m³';
        }
        if ($r['ozone'] !== null) {
            $measures[] = 'ozone ' . esc_html(number_format($r['ozone'], 1)) . ' µg/m³';
        }

        // The hour the reading was taken is part of the fact, not decoration.
        $taken = $r['taken'] !== '' ? $this->taken_label($r['taken']) : '';

        return '<style>' . $style . '</style>'
            . '<div class="lp-aq">'
            . '<h4>' . esc_html($title) . '</h4>'
            . '<div class="lp-aq-n">'
            . '<span class="lp-aq-v">' . esc_html((string) $r['aqi']) . '</span>'
            . '<span class="lp-aq-b"><span class="lp-aq-dot" style="background:' . esc_attr($r['colour']) . '"></span>'
            . esc_html($r['band']) . '</span>'
            . '</div>'
            . ($measures !== [] ? '<p class="lp-aq-m">' . implode(' · ', $measures) . '</p>' : '')
            . '<p class="lp-aq-src">US AQI' . ($taken !== '' ? ', measured ' . esc_html($taken) : '') . ' · Open-Meteo</p>'
            . '</div>';
    }

    /** "today at 3 PM" / "Sep 16, 3 PM" — the reading's own hour, in the site's timezone. */
    private function taken_label(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return '';
        }
        $sameDay = gmdate('Y-m-d', $ts) === gmdate('Y-m-d', strtotime('today'));

        return $sameDay ? 'today at ' . gmdate('g A', $ts) : gmdate('M j, g A', $ts);
    }
}
