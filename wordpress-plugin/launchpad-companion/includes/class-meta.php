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
}
