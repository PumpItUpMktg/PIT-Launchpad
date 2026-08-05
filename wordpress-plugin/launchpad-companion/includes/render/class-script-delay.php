<?php
/**
 * Delay heavy THIRD-PARTY scripts until first user interaction.
 *
 * The performance problem on a throttled connection is not execution (Total Blocking Time stays low —
 * these scripts run after first paint) but the DOWNLOAD: eager third-party JS from client-installed
 * plugins (Google Maps JS API, the LeadConnector chat widget, GTM via Site Kit, Cloudflare Insights)
 * saturates the pipe before the critical CSS/HTML arrives, so nothing paints for several seconds.
 * `defer`/`async` don't help — the bytes still compete for bandwidth. The fix is to not REQUEST these
 * scripts until the visitor interacts (scroll / click / key / touch) or the browser goes idle.
 *
 * How: buffer the frontend HTML and rewrite every `<script src>` whose host is on the allowlist to an
 * inert `type="text/plain"` placeholder carrying the URL in `data-lp-src`. A tiny vanilla loader (printed
 * once) swaps them back to executable — in document order, `async=false` to preserve dependency order — on
 * the first interaction, with an idle/timeout fallback. Scripts a delayed loader injects itself (the
 * LeadConnector `loader.js` → its `p-*.js`) are delayed transitively for free.
 *
 * Ownership note: these scripts belong to OTHER plugins / the CDN, not Launchpad — we only reach them
 * because we operate on the final HTML. The allowlist is host-based and filterable
 * (`lpc_delay_script_hosts`); a vendor changing its CDN host simply falls out of scope (fail-safe, no
 * worse than today). First-party / critical scripts (the theme, jQuery, Launchpad's own) are never
 * matched. Disable entirely with `add_filter('lpc_delay_scripts_enabled', '__return_false')`.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion\Render;

if (! defined('ABSPATH')) {
    exit;
}

final class ScriptDelay
{
    /**
     * The default third-party hosts to delay — the named culprits only. A match is the exact host or any
     * subdomain of it. Google Maps here is the heavy `maps.googleapis.com/api/js`; the theme's own Leaflet
     * areas-map is FIRST-PARTY and deliberately NOT listed (delaying its library could break an inline
     * `L.map()` init we don't control — that's a theme-side change, not this pass).
     *
     * @return list<string>
     */
    public static function default_hosts(): array
    {
        return [
            'maps.googleapis.com',
            'maps.gstatic.com',
            'widgets.leadconnectorhq.com',
            'stcdn.leadconnectorhq.com',
            'www.googletagmanager.com',
            'googletagmanager.com',
            'static.cloudflareinsights.com',
            'cloudflareinsights.com',
        ];
    }

    public function register(): void
    {
        if (is_admin()) {
            return;
        }
        add_action('template_redirect', [$this, 'maybe_start'], 0);
    }

    public function maybe_start(): void
    {
        if (! $this->should_run()) {
            return;
        }
        ob_start([$this, 'filter']);
    }

    /** Only full frontend HTML GET responses — never feeds, REST, AJAX, embeds, previews, or POSTs. */
    private function should_run(): bool
    {
        if (is_admin() || is_feed() || is_embed() || is_preview() || is_customize_preview()) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        return (bool) apply_filters('lpc_delay_scripts_enabled', true);
    }

    public function filter(string $html): string
    {
        // Only touch a full HTML document; skip anything else an output buffer might catch.
        if ($html === '' || stripos($html, '</body>') === false || stripos($html, '<script') === false) {
            return $html;
        }

        $hosts = $this->hosts();

        return $hosts === [] ? $html : self::transform($html, $hosts);
    }

    /** @return list<string> */
    private function hosts(): array
    {
        $hosts = apply_filters('lpc_delay_script_hosts', self::default_hosts());

        return is_array($hosts)
            ? array_values(array_filter(array_map(static fn ($h): string => strtolower(trim((string) $h)), $hosts)))
            : [];
    }

    /**
     * Pure transform (unit-testable): neutralize every external `<script src>` from $hosts and append the
     * interaction loader when at least one was delayed. Leaves everything else byte-for-byte intact.
     *
     * @param  list<string>  $hosts
     */
    public static function transform(string $html, array $hosts): string
    {
        $delayed = 0;
        $out = preg_replace_callback(
            '#<script\b([^>]*?)\ssrc\s*=\s*(["\'])(.*?)\2([^>]*)>\s*</script>#is',
            static function (array $m) use ($hosts, &$delayed): string {
                if (stripos($m[0], 'data-lp-delayed') !== false) {
                    return $m[0]; // already delayed (idempotent)
                }
                if (! self::host_matches(html_entity_decode($m[3]), $hosts)) {
                    return $m[0];
                }
                $delayed++;

                // Carry every other attribute; strip type/src (re-added by the loader) and async/defer
                // (the loader restores ordered execution). id is kept so nothing else that targets it breaks.
                $attrs = preg_replace('#\s(?:type|src)\s*=\s*(["\']).*?\1#i', '', $m[1].$m[4]);
                $attrs = preg_replace('#\s(?:async|defer)\b#i', '', (string) $attrs);

                return '<script type="text/plain" data-lp-delayed="1" data-lp-src="'.self::esc_attr($m[3]).'"'.$attrs.'></script>';
            },
            $html
        );

        if (! is_string($out) || $delayed === 0) {
            return is_string($out) ? $out : $html;
        }

        return self::inject_loader($out);
    }

    /** Exact host or a subdomain of an allowlisted host. Handles protocol-relative `//host/…` too. */
    private static function host_matches(string $src, array $hosts): bool
    {
        $host = self::host_of($src);
        if ($host === '') {
            return false;
        }
        foreach ($hosts as $h) {
            if ($h !== '' && ($host === $h || str_ends_with($host, '.'.$h))) {
                return true;
            }
        }

        return false;
    }

    private static function inject_loader(string $html): string
    {
        $tag = '<script id="lp-delay-loader">'.self::loader_js().'</script>';
        $replaced = preg_replace('#</body>#i', $tag.'</body>', $html, 1);

        return is_string($replaced) ? $replaced : $html.$tag;
    }

    /** The vanilla loader: on first interaction (or idle/timeout fallback) execute the delayed scripts in order. */
    private static function loader_js(): string
    {
        return <<<'JS'
(function(){var fired=false,evs=['scroll','mousemove','touchstart','keydown','click','pointerdown'];
function load(){if(fired){return;}fired=true;evs.forEach(function(e){window.removeEventListener(e,load);});
var q=[].slice.call(document.querySelectorAll('script[data-lp-delayed]')),i=0;
(function next(){if(i>=q.length){return;}var o=q[i++],n=document.createElement('script');
for(var a=0,at=o.attributes;a<at.length;a++){var k=at[a].name;if(k==='type'||k==='data-lp-delayed'||k==='data-lp-src'){continue;}n.setAttribute(k,at[a].value);}
n.src=o.getAttribute('data-lp-src');n.async=false;n.onload=n.onerror=next;o.parentNode.replaceChild(n,o);})();}
evs.forEach(function(e){window.addEventListener(e,load,{passive:true});});
if('requestIdleCallback' in window){requestIdleCallback(load,{timeout:8000});}else{setTimeout(load,8000);}})();
JS;
    }

    private static function esc_attr(string $value): string
    {
        return function_exists('esc_attr') ? esc_attr($value) : htmlspecialchars($value, ENT_QUOTES);
    }

    /** Lowercased host of a URL, resolving protocol-relative `//host/…`; '' when none. */
    private static function host_of(string $src): string
    {
        $host = parse_url($src, PHP_URL_HOST);
        if (! is_string($host) && str_starts_with($src, '//')) {
            $host = parse_url('https:'.$src, PHP_URL_HOST);
        }

        return is_string($host) ? strtolower($host) : '';
    }
}
