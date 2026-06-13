<?php

use Database\Seeders\WireframeKitSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The service-page CTA is a dual conversion block: a "Call Now" tel: button
 * derived from the primary location's phone (no config needed) PLUS an optional
 * embedded lead-capture form. `ghl_form_embed` holds that form — a GoHighLevel
 * embed snippet (or null). Null → the page renders call-button-only and still
 * publishes; the phone is the always-derivable floor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->text('ghl_form_embed')->nullable()->after('forms');
        });

        // Refresh the library kit slot_schema so the dual-conversion gating (cta on
        // has_location_phone, why_us on has_proof) reaches environments that already
        // ran the earlier reseed. Idempotent; kit stays version 1.
        (new WireframeKitSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->dropColumn('ghl_form_embed');
        });
    }
};
