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

    public const SEO = '_lp_seo';
    public const IMAGES = '_lp_images';
    public const KIND = '_lp_kind';
    public const PAGE_TYPE = '_lp_page_type';
    public const KIT = '_lp_kit';
    public const KIT_VERSION = '_lp_kit_version';
    public const SILO_ID = '_lp_silo_id';
    public const LOCKED = '_lp_locked';

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
}
