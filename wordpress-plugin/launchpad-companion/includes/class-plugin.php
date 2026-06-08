<?php
/**
 * Plugin bootstrap: wires the receiver (REST), renderer (dynamic tags +
 * template routing), SEO emission, sitemap, and redirects.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

use Launchpad\Companion\Render\TagManager;
use Launchpad\Companion\Render\TemplateRouter;
use Launchpad\Companion\Rest\Routes;
use Launchpad\Companion\Seo\Head;
use Launchpad\Companion\Seo\Schema;
use Launchpad\Companion\Seo\Breadcrumbs;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        // Receiver.
        add_action('rest_api_init', [new Routes(), 'register']);

        // Renderer.
        add_action('elementor/dynamic_tags/register', [new TagManager(), 'register']);
        ( new TemplateRouter() )->register();

        // SEO (native, no SEO plugin).
        ( new Head() )->register();
        ( new Schema() )->register();
        add_shortcode('lp_breadcrumbs', [Breadcrumbs::class, 'shortcode']);

        // Sitemap + redirects.
        ( new Sitemap() )->register();
        ( new Redirects() )->register();
    }

    public static function activate(): void
    {
        ServiceUser::install();
        ( new Sitemap() )->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
