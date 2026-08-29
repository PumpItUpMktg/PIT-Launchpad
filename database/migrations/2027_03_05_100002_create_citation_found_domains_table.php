<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — every result domain the citation scan surfaces for a location, persisted at pull time.
 * The platform's rank-tracking SERP results are cache-only (never retained), so the citation module captures
 * its OWN scan's domains here. A domain that matches the catalog carries `directory_id`; an UNMATCHED domain
 * (directory_id null) is a candidate the operator can confirm into the catalog (PR5/PR8 harvesting). Idempotent
 * per (site, location, domain) — re-scans bump last_seen_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citation_found_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->index();
            $table->foreignUlid('directory_id')->nullable()->constrained()->nullOnDelete(); // matched catalog dir, or null = candidate
            $table->string('found_url')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'location_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citation_found_domains');
    }
};
