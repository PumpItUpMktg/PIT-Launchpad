<?php
/**
 * Plugin bootstrap: wires the receiver (REST), renderer (dynamic tags +
 * template routing), SEO emission, sitemap, and redirects.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

use Launchpad\Companion\Admin\SlotsScreen;
use Launchpad\Companion\Content\AreaTaxonomy;
use Launchpad\Companion\Content\EditGuard;
use Launchpad\Companion\Content\KitTaxonomy;
use Launchpad\Companion\Render\Assets;
use Launchpad\Companion\Render\BrandPaint;
use Launchpad\Companion\Render\ScriptDelay;
use Launchpad\Companion\Render\WeatherAlert;
use Launchpad\Companion\Render\Shortcodes;
use Launchpad\Companion\Render\SiteChrome;
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

        // The lp_kit per-page marker — the Theme Builder display-condition target
        // that renders each kit's mapped template.
        add_action('init', [KitTaxonomy::class, 'register']);

        // The lp_area town taxonomy (§B) — the towns a post references, queryable so a
        // location page can list its town's posts.
        add_action('init', [AreaTaxonomy::class, 'register']);

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

        // Universal header/footer chrome — [lp_header]/[lp_footer] render the pushed
        // site profile (brand/NAP/nav) for the block theme's template parts.
        ( new SiteChrome() )->register();

        // Baseline design layer — styles the lp-* blocks (keyed to the Global Kit
        // CSS variables) so a generated page is presentable without a designer.
        ( new Assets() )->register();
        // Brand paint: on a block theme, re-declare the chosen palette's --wp--preset--color--* (and
        // shape tokens) as a late :root override, so the pushed colors paint deterministically even
        // when WordPress's global-styles merge doesn't reflect the user global-styles write.
        ( new BrandPaint() )->register();
        ( new WeatherAlert() )->register();
        // Performance: delay heavy third-party scripts (Maps JS / LeadConnector chat / GTM / Cloudflare
        // Insights) until first interaction so they stop starving the critical path on throttled links.
        ( new ScriptDelay() )->register();

        // SEO (native; suppress competing SEO plugins on managed posts).
        // Force core title-tag so the document <title> is emitted ONCE, through
        // our pre_get_document_title filter (Head::title) — not a second hand-
        // printed tag. Idempotent if the theme already declares title-tag support.
        add_action('after_setup_theme', [self::class, 'enable_title_tag'], 20);
        ( new Head() )->register();
        ( new Schema() )->register();
        ( new Suppressor() )->register();
        add_shortcode('lp_breadcrumbs', [Breadcrumbs::class, 'shortcode']);

        // Admin reference (Launchpad → Slots & Shortcodes).
        ( new SlotsScreen() )->register();

        // Sitemap + redirects + IndexNow key file.
        ( new Sitemap() )->register();
        ( new Redirects() )->register();
        ( new IndexNow() )->register();

        // Self-heal on UPDATE: the activation hook only runs on a manual (de)activate, so a plugin
        // update (or an auto-update) never re-grants the service capability. When the stored version
        // differs from the running one, re-run install() once — repairs a user/role whose capability
        // was lost in a site migration without needing a deactivate/reactivate. Hooked on `init` (not
        // admin_init) so it fires for REST requests too — the very connect attempt can self-heal.
        add_action('init', [self::class, 'maybe_upgrade']);
    }

    /** Run install() once after a version change (see boot()). */
    public static function maybe_upgrade(): void
    {
        if (get_option('lpc_version') !== LPC_VERSION) {
            ServiceUser::install();
            update_option('lpc_version', LPC_VERSION);
        }
    }

    public static function register_page_categories(): void
    {
        register_taxonomy_for_object_type('category', 'page');
    }

    /**
     * Let WordPress core render the single <title> tag (via pre_get_document_title,
     * which Head filters). A managed head must not also hand-print a title.
     */
    public static function enable_title_tag(): void
    {
        add_theme_support('title-tag');
    }

    public static function activate(): void
    {
        ServiceUser::install();
        update_option('lpc_version', LPC_VERSION);
        self::register_page_categories();
        KitTaxonomy::register();
        AreaTaxonomy::register();
        ( new Sitemap() )->add_rewrite_rules();
        ( new IndexNow() )->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
