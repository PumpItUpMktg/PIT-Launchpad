<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-level business contact phone + a distinct emergency/after-hours line. The guided wizard never
 * captured a phone (Locations only carried address/geo), so guided-onboarded tenants shipped with no
 * number on the home page. This is the canonical business phone, captured early; a Location's own
 * phone still wins where present (multi-location NAP), else the readers fall back to this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('offers_emergency');
            $table->string('emergency_phone')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'emergency_phone']);
        });
    }
};
