<?php
/**
 * Severe-weather alert banner — a LIVE, dismissible "heavy rain expected — is your {noun} ready?" bar.
 *
 * The control plane pushes the config on the site profile (coords + on/off + CTA); this fetches the
 * forecast ITSELF from Open-Meteo (free, no key) so the alert stays current without republishing, and
 * caches it in a 6-hour transient so a busy site makes one request, not one per visit. The banner shows
 * only when a day in the next ~10 days is forecast to exceed the rain threshold; otherwise nothing
 * renders. Self-contained markup (inline styles + a tiny dismiss script), so it needs no enqueue and
 * works on any theme. Every failure is quiet — a down forecast API never breaks the page.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

use Launchpad\Companion\Content\SiteProfileStore;

if (! defined('ABSPATH')) {
    exit;
}

final class WeatherAlert
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';

    private const CACHE_PREFIX = 'lp_wx_forecast_';

    /** Look-ahead window (days) and the default "heavy rain" threshold (inches in a single day). */
    private const FORECAST_DAYS = 10;

    private const DEFAULT_THRESHOLD_IN = 1.0;

    public function register(): void
    {
        add_action('wp_body_open', [$this, 'render']);
    }

    public function render(): void
    {
        if (is_admin() || is_feed() || is_robots()) {
            return;
        }

        $cfg = SiteProfileStore::get()['alert'] ?? [];
        if (empty($cfg['enabled']) || ! isset($cfg['lat'], $cfg['lng'])) {
            return;
        }

        $event = $this->severe_rain((float) $cfg['lat'], (float) $cfg['lng']);
        if ($event === null) {
            return;
        }

        echo $this->markup($event, $cfg); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* below
    }

    /**
     * The soonest upcoming day whose forecast precipitation clears the threshold, or null.
     *
     * @return array{date: string, label: string, inches: float}|null
     */
    private function severe_rain(float $lat, float $lng): ?array
    {
        $threshold = (float) apply_filters('lp_weather_alert_threshold', self::DEFAULT_THRESHOLD_IN);

        return self::wettest_day($this->forecast($lat, $lng), $threshold);
    }

    /**
     * Pure evaluation (unit-testable, no network): given Open-Meteo daily {time[], precipitation_sum[]},
     * return the FIRST day at/over the threshold with a friendly label ("this Saturday" / "Jul 24").
     *
     * @param  array{time?: list<string>, precipitation_sum?: list<float|int|null>}  $daily
     * @return array{date: string, label: string, inches: float}|null
     */
    public static function wettest_day(array $daily, float $threshold): ?array
    {
        $dates = isset($daily['time']) && is_array($daily['time']) ? $daily['time'] : [];
        $precip = isset($daily['precipitation_sum']) && is_array($daily['precipitation_sum']) ? $daily['precipitation_sum'] : [];

        foreach ($dates as $i => $date) {
            $inches = isset($precip[$i]) && is_numeric($precip[$i]) ? (float) $precip[$i] : 0.0;
            if ($inches < $threshold) {
                continue;
            }

            return [
                'date' => (string) $date,
                'label' => self::day_label((string) $date),
                'inches' => round($inches, 1),
            ];
        }

        return null;
    }

    /** A human day label: "today" / "tomorrow" / "this Saturday" (within a week) / "Jul 24" beyond. */
    private static function day_label(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }
        $days = (int) floor(($ts - strtotime('today')) / DAY_IN_SECONDS);
        if ($days <= 0) {
            return 'today';
        }
        if ($days === 1) {
            return 'tomorrow';
        }
        if ($days < 7) {
            return 'this ' . gmdate('l', $ts);
        }

        return gmdate('M j', $ts);
    }

    /**
     * The cached daily forecast for a point. One request per 6h per coordinate; any failure caches an
     * empty result for an hour so a down API isn't hammered.
     *
     * @return array{time?: list<string>, precipitation_sum?: list<float|int|null>}
     */
    private function forecast(float $lat, float $lng): array
    {
        $key = self::CACHE_PREFIX . md5($lat . ',' . $lng);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $url = add_query_arg([
            'latitude' => $lat,
            'longitude' => $lng,
            'daily' => 'precipitation_sum',
            'forecast_days' => self::FORECAST_DAYS,
            'precipitation_unit' => 'inch',
            'timezone' => 'auto',
        ], self::ENDPOINT);

        $response = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($key, [], HOUR_IN_SECONDS);

            return [];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $daily = is_array($body) && isset($body['daily']) && is_array($body['daily']) ? $body['daily'] : [];

        set_transient($key, $daily, 6 * HOUR_IN_SECONDS);

        return $daily;
    }

    /**
     * The dismissible banner. Server-rendered, then a tiny inline script hides it if the visitor already
     * dismissed THIS event (keyed by date in localStorage) — so a new storm re-shows it. All dynamic
     * values are escaped.
     *
     * @param  array{date: string, label: string, inches: float}  $event
     * @param  array<string, mixed>  $cfg
     */
    private function markup(array $event, array $cfg): string
    {
        $noun = trim((string) ($cfg['noun'] ?? 'sump pump')) ?: 'sump pump';
        $ctaUrl = trim((string) ($cfg['cta_url'] ?? ''));
        $ctaLabel = trim((string) ($cfg['cta_label'] ?? '')) ?: 'Learn more';

        $line = sprintf(
            'Heavy rain expected %s (about %s in.) — is your %s ready?',
            $event['label'],
            number_format($event['inches'], 1),
            $noun
        );

        $cta = $ctaUrl !== ''
            ? '<a class="lp-wx-cta" href="' . esc_url($ctaUrl) . '">' . esc_html($ctaLabel) . '</a>'
            : '';

        $style = '.lp-wx{display:flex;align-items:center;gap:12px;justify-content:center;flex-wrap:wrap;'
            . 'background:#0b3b5a;color:#fff;font:600 14px/1.4 system-ui,sans-serif;padding:10px 16px;text-align:center}'
            . '.lp-wx a.lp-wx-cta{color:#0b3b5a;background:#fff;border-radius:6px;padding:5px 12px;text-decoration:none;white-space:nowrap}'
            . '.lp-wx button.lp-wx-x{background:none;border:0;color:#fff;font-size:18px;line-height:1;cursor:pointer;padding:0 4px}';

        // Hide if this exact storm date was already dismissed; the × stores it and removes the bar.
        $script = '(function(){var b=document.getElementById("lp-wx");if(!b)return;var d=b.getAttribute("data-date");'
            . 'try{if(localStorage.getItem("lp-wx-dismissed")===d){b.remove();return;}}catch(e){}'
            . 'var x=b.querySelector(".lp-wx-x");if(x){x.addEventListener("click",function(){try{localStorage.setItem("lp-wx-dismissed",d);}catch(e){}b.remove();});}})();';

        return '<style>' . $style . '</style>'
            . '<div class="lp-wx" id="lp-wx" role="alert" data-date="' . esc_attr($event['date']) . '">'
            . '<span>&#9928; ' . esc_html($line) . '</span>'
            . $cta
            . '<button type="button" class="lp-wx-x" aria-label="Dismiss">&times;</button>'
            . '</div>'
            . '<script>' . $script . '</script>';
    }
}
