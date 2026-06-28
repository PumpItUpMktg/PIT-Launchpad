<?php

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-seed the library wireframe kits so the new HUB kit (hub-page, page_type='hub') reaches existing
 * environments. Silo-pillar (hub) pages previously resolved no kit at all, so they published as a flat
 * dynamic-template fallback instead of the verified service-hub Elementor body. Adding the kit makes
 * the materializer link hub pages and the publish path compose their native body.
 *
 * Same idiom as the prior reseeds ({@see 2026_11_24_100001_reseed_library_kits_with_standard_pages}):
 * a deploy runs `migrate` (not `db:seed`), so each new batch of kits needs a reseed migration —
 * idempotent (the seeder upserts on site_id=null + name + version). `launchpad:sync-kits` is the
 * manual equivalent. NOTE: existing hub Content rows materialized before this still carry a null
 * wireframe_kit_id; relink them with `launchpad:relink-page-kits {site} --apply`, then re-publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new WireframeKitSeeder)->run();
    }

    public function down(): void
    {
        // Forward-only: the kit JSON is the source of truth; no inverse.
    }
};
