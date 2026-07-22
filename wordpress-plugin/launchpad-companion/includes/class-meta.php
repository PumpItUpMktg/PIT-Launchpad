<?php
/**
 * Central registry of meta keys and option names. The slot payload lives under a
 * single consolidated post-meta key, never per-slot rows.
 *
 * @package Launchpad\Companion
 */

namespace Launchpad\Companion;

if (! defined('ABSPATH')) {
    exit;
}

final class Meta
{
    /** The control-plane ULID — the idempotency key for /content upserts. */
    public const CONTENT_ID = '_lp_content_id';

    /** The single consolidated slot_payload blob. */
    public const SLOTS = '_lp_slots';

    /**
     * Per-slot readable mirror prefix. The consolidated SLOTS blob is protected
     * (leading underscore) and serialized — invisible to the Custom Fields UI and
     * unbindable. Each scalar slot is ALSO written to `lp_slot_{key}` (NO leading
     * underscore), so it shows in the Custom Fields box and is bindable from a
     * Theme Builder template via Elementor's native Post Custom Field dynamic tag.
     */
    public const SLOT_PREFIX = 'lp_slot_';

    public const SEO = '_lp_seo';
    public const IMAGES = '_lp_images';
    public const KIND = '_lp_kind';
    public const PAGE_TYPE = '_lp_page_type';
    public const KIT = '_lp_kit';
    public const KIT_VERSION = '_lp_kit_version';
    public const SILO_ID = '_lp_silo_id';
    public const LOCKED = '_lp_locked';

    /** The control-plane ULID of this page's PARENT hub (URL nesting: a town page under its location
     * hub). Stored from the /content blob's `parent_content_id` so post_parent can be resolved even if
     * the parent is pushed AFTER the child — a later hub upsert re-parents its orphaned children by
     * matching this meta. Empty for a flat/top-level page. */
    public const PARENT_ID = '_lp_parent_id';

    /** The "Areas we serve" interactive-map geometry (served-county polygons + tiered town
     * points). Stored from the /content blob's `service_area_map` and printed as a
     * `window.lpAreaMap` global for the block theme's Leaflet init — kept OUT of post_content
     * because kses would strip the embedded geometry. */
    public const AREA_MAP = '_lp_area_map';

    /** The page's lead-form embed (a GHL iframe, operator-configured per page on the control
     * plane). Stored from the /content blob's `form_embed` and rendered by the [lp_form]
     * shortcode — kept OUT of post_content because kses strips iframes on save. */
    public const FORM_EMBED = '_lp_form_embed';

    /** The operator-resolved Elementor template id for this page's kit (§7b
     * mapping). Stored for reference + the Theme Builder condition the Slots
     * screen documents; rendering is driven by the lp_kit term condition, not
     * this value. */
    public const TEMPLATE_ID = '_lp_template_id';

    /** On an imported Theme Builder template (`elementor_library`): the kit it
     * serves — the idempotency marker for /kit-template re-pushes. */
    public const KIT_TEMPLATE = '_lp_kit_template';

    /** On an imported template: the intended Display Condition (taxonomy/term/
     * location/rule) — advisory reference the operator confirms when Elementor
     * Pro is absent, set automatically when present. */
    public const KIT_TEMPLATE_CONDITION = '_lp_kit_template_condition';

    /** Set true when a non-plugin save_post edits a managed post (edited-in-WP). */
    public const LOCALLY_EDITED = '_lp_locally_edited';

    /** Fingerprint (content hash) of the last engine push — guards the edit detector. */
    public const LAST_PUSH = '_lp_last_push';

    /** Per-attachment marker: the R2 source URL this media was sideloaded from. */
    public const IMAGE_SOURCE = '_lp_image_source';

    /** silo_id => term_id map. */
    public const OPTION_SILOS = 'lp_silo_categories';

    /** page_type => Elementor template post id map. */
    public const OPTION_TEMPLATES = 'lp_templates';

    /** from_url => [to_url, code] map. */
    public const OPTION_REDIRECTS = 'lp_redirects';

    /** "{kit}@{version}" => ['kit','version','slots'] — the contract kit defs the
     * Slots & Shortcodes reference screen reads (per kit/version, not observed data). */
    public const OPTION_KIT_DEFINITIONS = 'lp_kit_definitions';

    /** "--wf-*" => value map — the per-tenant brand tokens (push-brand-kit), printed
     * as a :root block by the wf-* base stylesheet (Assets). */
    public const OPTION_BRAND_TOKENS = 'lp_brand_tokens';

    /** trust|bold|warm — the chosen structure preset; emitted as a body.wf-structure-
     * {slug} class by TemplateRouter so the matching token bundle applies. */
    public const OPTION_STRUCTURE_PRESET = 'lp_structure_preset';

    /** The pushed per-tenant site profile (brand/NAP/nav) rendered by the universal
     * header/footer chrome — see SiteProfileStore + SiteChrome. */
    public const OPTION_SITE_PROFILE = 'lp_site_profile';

    /** The active brand style's resolved tokens — { colors: {slug=>hex}, custom: {radius,
     * heading_weight, heading_letter_spacing} }, written by StyleStore on every /style push.
     * BrandPaint prints them as a LATE :root override of the block theme's --wp--preset--color--*
     * and --wp--custom--* variables, so the chosen palette paints even when WordPress's
     * global-styles merge does not reflect the user global-styles write (the "green flag, still
     * base blue" failure). The theme's blocks already consume these variables. */
    public const OPTION_BRAND_PAINT = 'lp_brand_paint';
}
