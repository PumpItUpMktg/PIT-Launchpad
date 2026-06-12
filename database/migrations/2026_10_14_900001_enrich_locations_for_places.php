<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §7 onboarding (Week 2, Slice 1) — enrich `locations` with the Google Places
 * data the import flow captures. All nullable; none is ever a publish gate.
 * `address` (the formatted display string) is unchanged; `address_components`
 * carries the structured breakdown. `hours` is already a per-day JSON column —
 * standardized to `{"mon": {"open","close"}, "sun": "closed", …}` in the model
 * layer, no column change needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('place_id')->nullable()->index()->after('name');
            $table->string('gbp_url')->nullable()->after('booking_url');
            $table->decimal('lat', 10, 7)->nullable()->after('gbp_url');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->jsonb('address_components')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['place_id', 'gbp_url', 'lat', 'lng', 'address_components']);
        });
    }
};
