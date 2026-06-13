<?php

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-seed the library wireframe kits so the §3a entity-slot policy change reaches
 * existing environments: service-page cta/contact_block no longer gate on
 * conversion.primary_action / location.nap (cta derives the phone, contact_block
 * the NAP — never block), and proof_strip is conditional on ≥2 substantiated proof
 * (omit, don't block). The seeder is idempotent (updateOrCreate on
 * site_id=null + page_type + version), and the kit stays version 1 — only the
 * slot_schema is refreshed, so pages pinned to v1 pick up the new rules.
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
