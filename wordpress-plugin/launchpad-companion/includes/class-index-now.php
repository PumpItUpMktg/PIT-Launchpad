<?php
/**
 * Serves the IndexNow verification key at /{key}.txt. The control plane owns the key and deploys it
 * via the authed launchpad/v1/indexnow-key endpoint; hosting the file on this domain proves ownership
 * so IndexNow (Bing / Yandex / Seznam / Naver) accepts URL pings from the control plane. No SEO plugin.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

if (! defined('ABSPATH')) {
    exit;
}

final class IndexNow
{
    private const OPTION = 'lpc_indexnow_key';

    public function register(): void
    {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'maybe_render']);
    }

    public function add_rewrite_rules(): void
    {
        $key = self::key();
        if ($key !== '') {
            add_rewrite_rule('^' . preg_quote($key, '/') . '\.txt$', 'index.php?lp_indexnow=1', 'top');
        }
    }

    /**
     * @param  array<int, string>  $vars
     * @return array<int, string>
     */
    public function query_vars(array $vars): array
    {
        $vars[] = 'lp_indexnow';

        return $vars;
    }

    public function maybe_render(): void
    {
        $flag = get_query_var('lp_indexnow');
        if ($flag === '' || $flag === false) {
            return;
        }

        $key = self::key();
        if ($key === '') {
            status_header(404);
            exit;
        }

        header('Content-Type: text/plain; charset=UTF-8');
        echo esc_html($key); // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    public static function key(): string
    {
        return (string) get_option(self::OPTION, '');
    }

    /**
     * Store the control plane's key (validated) and flush rewrites so /{key}.txt starts serving.
     * Idempotent — re-storing the same key is a no-op.
     */
    public static function store(string $key): bool
    {
        $key = (string) preg_replace('/[^a-zA-Z0-9-]/', '', $key);
        if (strlen($key) < 8) {
            return false;
        }

        if (self::key() !== $key) {
            update_option(self::OPTION, $key);
            ( new self() )->add_rewrite_rules();
            flush_rewrite_rules(false);
        }

        return true;
    }
}
