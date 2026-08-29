<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the ONE authoritative NAP submission payload per location. Every work order renders
 * from it, so every submission is byte-identical by construction — the root fix for NAP drift (Suite vs Ste,
 * a tracking number instead of the main line, a stale address). Carries its OWN discrete address parts
 * (locations stores only a formatted `address` string + `address_components`), plus the canonical
 * phone_primary (the location's local number, never a shared/toll-free line) and a verification_email
 * (per-location alias on a domain we control, so directory verification mails don't die in the client's inbox).
 *
 * Location-scoped: site_id (tenant isolation via BelongsToSite) + a unique location_id (one profile per GBP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_nap_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete()->unique();

            $table->string('business_name');
            $table->string('address_1');
            $table->string('address_2')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('postal');
            $table->string('phone_primary');                 // the location's own local number — canonical
            $table->string('phone_secondary')->nullable();   // shared/corporate/toll-free, never primary
            $table->string('website_url')->nullable();
            $table->jsonb('hours')->nullable();
            $table->jsonb('categories')->nullable();
            $table->text('description_short')->nullable();
            $table->text('description_long')->nullable();
            $table->text('service_area_description')->nullable();
            $table->string('logo_url')->nullable();
            $table->jsonb('photo_urls')->nullable();
            $table->string('verification_email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_nap_profiles');
    }
};
