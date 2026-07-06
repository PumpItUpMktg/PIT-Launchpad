<?php

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-seed the library wireframe kits so the home-page kit's recalibrated slot limits reach existing
 * environments. The `service_area` slot renders as the hero EYEBROW (a short "trade · region" label),
 * but its hint said "name the markets served" — so for a business with many towns the drafter wrote a
 * 357-char town list and the page failed the 200-char cap ("Slot [service_area] is 357 chars, maximum
 * 200"). Fixed the hint (short eyebrow, not a town list) and gave `hero_subhead` headroom (200→260).
 *
 * Same idiom as the prior reseeds: a deploy runs `migrate` (not `db:seed`), so each kit change needs a
 * reseed migration — idempotent (the seeder upserts on site_id=null + name + version).
 * `launchpad:sync-kits` is the manual/immediate equivalent.
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
