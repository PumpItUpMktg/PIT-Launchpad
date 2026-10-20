<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Business Profiles as Places reports them (§ Roadmap): the tenant's own offices and the map-pack
 * competitors the geo grid keeps finding beside them, one row per place per site, refreshed monthly. The
 * office roadmap compares an office's row against its pack winners' rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('place_id');
            $table->ulid('location_id')->nullable()->index();   // set when this is one of the tenant's own offices
            $table->string('name');
            $table->string('domain')->nullable()->index();
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('ratings_total')->nullable();
            $table->json('types')->nullable();
            $table->string('primary_type')->nullable();
            $table->unsignedSmallInteger('photo_count')->nullable();
            $table->string('business_status')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('website', 2048)->nullable();
            $table->unsignedInteger('referring_domains')->nullable();  // from the backlink index, when a domain is known
            $table->unsignedSmallInteger('authority')->nullable();     // 0-100
            $table->timestamp('refreshed_at');
            $table->timestamps();

            $table->unique(['site_id', 'place_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_profiles');
    }
};
