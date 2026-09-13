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

        // Core's 404 "guess" (redirect_guess_404_permalink) 301s any unknown URL to a live post whose slug
        // matches its last segment — so a dead /bedminster-nj/washington-nj lands on /hackensack-nj/washington-nj,
        // a DIFFERENT town 40 miles away (Washington exists in four NJ counties; Franklin, Monroe, Hamilton and
        // Union likewise). The control plane's redirect map above is the only redirect authority: a dead path
        // it does not cover must 404, never guess.
        add_filter('do_redirect_guess_404_permalink', '__return_false');
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
