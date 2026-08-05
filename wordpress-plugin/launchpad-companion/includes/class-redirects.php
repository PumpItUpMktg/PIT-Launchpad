<?php
/**
 * Applies the 301 redirects stored from the /redirects contract, matched on the
 * normalized request path.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

use Launchpad\Companion\Content\RedirectStore;

if (! defined('ABSPATH')) {
    exit;
}

final class Redirects
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_redirect'], 0);
    }

    public function maybe_redirect(): void
    {
        $request = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        if ($request === '') {
            return;
        }

        $path = RedirectStore::normalize($request);

        $map = get_option(Meta::OPTION_REDIRECTS, []);

        if (! is_array($map) || ! isset($map[$path])) {
            return;
        }

        $target = $map[$path];
        $to = (string) ($target['to_url'] ?? '');
        $code = (int) ($target['code'] ?? 301);

        // A 4xx code (e.g. 410 Gone) FLUSHES a legacy URL with no destination — emit the status and stop.
        // wp_safe_redirect needs a Location and can't express "gone", so a redirect is wrong here. Used to
        // retire out-of-footprint or dead legacy pages from the index (Stage 8.2).
        if ($code >= 400) {
            status_header($code);
            nocache_headers();
            exit;
        }

        if ($to === '') {
            return;
        }

        wp_safe_redirect($to, $code);
        exit;
    }
}
