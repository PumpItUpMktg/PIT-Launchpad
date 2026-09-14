<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Town Rank (PR 1): the WEBSITE's organic Google rank per covered town, site-wide — the organic sibling of the
 * geo grid's map-pack coverage scan. A scan header is one (site × keyword × mode × run); a point is one town
 * (a coverage_area centroid) with the rank the site's domain holds in that town's results, the URL that
 * ranks, and the top results present, so an operator can see who outranks them there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('town_rank_scans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('keyword_id')->index();                 // deferred-FK style (Keyword has global scopes)
            $table->string('mode');                              // local (query from the town) | town_query ("{query} {town} {ST}", national)
            $table->string('provider')->default('dataforseo');
            $table->string('status')->default('pending');        // pending | complete | partial
            $table->unsignedInteger('points_count')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'keyword_id', 'mode', 'scanned_at']);
        });

        Schema::create('town_rank_points', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('scan_id')->constrained('town_rank_scans')->cascadeOnDelete();
            $table->ulid('coverage_area_id')->nullable()->index(); // the town this point measures
            $table->string('label');                               // town name, for at-a-glance reads
            $table->string('state', 2)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('query');                               // the exact query sent
            $table->unsignedSmallInteger('rank')->nullable();      // null = the site's domain not within depth (or not collected)
            $table->string('ranking_url', 2048)->nullable();
            $table->json('top_results')->nullable();               // top 5: {position, url, domain}
            $table->string('provider_task_id')->nullable();
            $table->timestamp('collected_at')->nullable();         // null = awaiting its task result
            $table->timestamps();

            $table->index(['site_id', 'coverage_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('town_rank_points');
        Schema::dropIfExists('town_rank_scans');
    }
};
