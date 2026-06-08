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

        if ($to === '') {
            return;
        }

        wp_safe_redirect($to, $code);
        exit;
    }
}
