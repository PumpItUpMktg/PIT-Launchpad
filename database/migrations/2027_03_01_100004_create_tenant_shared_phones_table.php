<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the tenant's shared phone numbers (corporate / emergency / tracking). These appear
 * across many locations by design, so in the citation scan they carry ZERO attribution signal — address
 * decides — UNLESS a location genuinely owns one as its GBP primary (`owning_location_id`), and even then the
 * number only attributes when the address also corroborates (a bare number match would sweep every orphan
 * listing in the tenant into that location). `owning_location_id` defaults to null; populating it is a
 * deliberate onboarding decision, required for any multi-location tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_shared_phones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->string('purpose');                       // SharedPhonePurpose: corporate|emergency|tracking
            $table->foreignUlid('owning_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->timestamps();

            $table->unique(['site_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_shared_phones');
    }
};
