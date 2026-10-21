<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google reviews shown on an office's location page: the five Places reports as most relevant for the
 * office's own listing, replaced whole on every profile refresh (Google caps caching of Place data at
 * thirty days; the monthly refresh re-pushes the pages). Competitors' reviews are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('place_id');
            $table->string('author_name');
            $table->string('author_url', 2048)->nullable();
            $table->unsignedTinyInteger('rating');
            $table->text('text');
            $table->string('relative_time')->nullable();   // Google's own "a month ago"
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('refreshed_at');
            $table->timestamps();

            $table->index(['site_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_reviews');
    }
};
