<?php

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-seed the library wireframe kits so the lowered repeater minimums on the standard-page kits
 * reach existing environments. The `values` (about), `differentiators` (why-choose-us) and
 * `service_highlights` (home) slots required min 3 items, but they are "phrase each captured/derived
 * item, never invent" — hard-bounded by what the operator actually captured. A brand with only 2
 * captured values made the About page draft fail the kit schema ("Slot [values] has 2 items, minimum
 * 3") instead of rendering the 2 it has. The minimums are now 1 (render what's captured; the
 * condition / required-intake gate still omits or holds when there are zero).
 *
 * Same idiom as the prior reseeds: a deploy runs `migrate` (not `db:seed`), so each kit change needs
 * a reseed migration — idempotent (the seeder upserts on site_id=null + name + version).
 * `launchpad:sync-kits` is the manual equivalent.
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
