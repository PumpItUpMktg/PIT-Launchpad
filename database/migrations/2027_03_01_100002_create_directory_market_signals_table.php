<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the MARKET-DEPENDENT half of a directory's rating. A county chamber's value differs
 * sharply by county, so the global attributes stay on `directories` and the per-geography ones live here:
 * whether the directory ranks for the market's money terms, its local SERP positions, the competitor count
 * seen there, and a `seo_value_local` that overrides the global value for that market. Global (no site_id) —
 * a market signal is a property of (directory × geography), reusable across tenants in that geography.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_market_signals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('directory_id')->constrained()->cascadeOnDelete();
            $table->string('geo_value');                              // the market this signal is for
            $table->boolean('ranks_for_local_terms')->default(false);
            $table->jsonb('local_serp_positions')->nullable();        // which terms, what position
            $table->unsignedSmallInteger('competitor_count')->nullable();
            $table->unsignedTinyInteger('seo_value_local')->nullable(); // 0-100, overrides the global value here
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['directory_id', 'geo_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_market_signals');
    }
};
