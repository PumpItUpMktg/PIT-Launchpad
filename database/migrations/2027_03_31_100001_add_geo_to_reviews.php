<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store a review's OWN geography (Review Capture — town/geo fix). Until now a review carried only its
 * owning GBP `location_id` (a market location, not a town) plus a private `service_address`; the rendered
 * town was the parent location's city, so a Belleville customer's review displayed as Clifton. These
 * additive columns let a review carry its own town/state/postal (from the import sheet or first-party
 * capture) and a geocoded point, so the location-page reviews section can filter by served-town membership
 * with a Haversine radius fallback and display the review's real town. All nullable — existing rows are
 * geo-less until the backfill runs; new imports/first-party captures populate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->string('town')->nullable()->after('service_address');
            $table->string('state')->nullable()->after('town');
            $table->string('postal_code')->nullable()->after('state');
            $table->decimal('lat', 10, 7)->nullable()->after('postal_code');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn(['town', 'state', 'postal_code', 'lat', 'lng']);
        });
    }
};
