<?php
/**
 * Plugin bootstrap: wires the receiver (REST), renderer (dynamic tags +
 * template routing), SEO emission, sitemap, and redirects.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

use Launchpad\Companion\Content\EditGuard;
use Launchpad\Companion\Render\Shortcodes;
use Launchpad\Companion\Render\TagManager;
use Launchpad\Companion\Render\TemplateRouter;
use Launchpad\Companion\Rest\Routes;
use Launchpad\Companion\Seo\Breadcrumbs;
use Launchpad\Companion\Seo\Head;
use Launchpad\Companion\Seo\Schema;
use Launchpad\Companion\Seo\Suppressor;

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
        // Native categories on pages — service/location pages are kind=page and
        // must carry the silo category (backs the breadcrumb + link).
        add_action('init', [self::class, 'register_page_categories']);

        // Receiver.
        add_action('rest_api_init', [new Routes(), 'register']);

        // Locked / locally-edited protocol.
        ( new EditGuard() )->register();

        // Renderer. Shortcodes are the Elementor-version-independent binding path
        // (no Elementor dependency); the classic lp/* dynamic tags register on top
        // for the V3 editor, guarded so a missing dynamic-tag API can't fatal.
        ( new Shortcodes() )->register();
        add_action('elementor/dynamic_tags/register', [new TagManager(), 'register']);
        ( new TemplateRouter() )->register();

        // SEO (native; suppress competing SEO plugins on managed posts).
        ( new Head() )->register();
        ( new Schema() )->register();
        ( new Suppressor() )->register();
        add_shortcode('lp_breadcrumbs', [Breadcrumbs::class, 'shortcode']);

        // Sitemap + redirects.
        ( new Sitemap() )->register();
        ( new Redirects() )->register();
    }

    public static function register_page_categories(): void
    {
        register_taxonomy_for_object_type('category', 'page');
    }

    public static function activate(): void
    {
        ServiceUser::install();
        self::register_page_categories();
        ( new Sitemap() )->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
