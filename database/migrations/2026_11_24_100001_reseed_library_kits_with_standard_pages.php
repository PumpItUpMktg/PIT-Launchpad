<?php

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-seed the library wireframe kits so the STANDARD-PAGE kits (home / about / why-choose-us / faq,
 * added with the standard-page composer) reach existing environments. The earlier reseed migration
 * ({@see 2026_10_15_900002_reseed_service_kit_entity_policy}) ran BEFORE these kits were added to the
 * seeder, and migrations run once — so on a deployed box that already applied it, the new kits were
 * never seeded and every Core page read "composer pending" with otherwise-current code.
 *
 * This is the project's idiom for shipping kit data to prod: a deploy runs `migrate` (not `db:seed`),
 * so each batch of new kits needs a reseed migration (idempotent — the seeder upserts on
 * site_id=null + name + version). `launchpad:sync-kits` is the manual/immediate equivalent.
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
