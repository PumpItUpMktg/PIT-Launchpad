<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §1 — the job record (site-scoped). Named `job_captures`, NOT `jobs`, because the database
 * queue driver already owns the `jobs` table. Privacy is baked into the schema (§4): the true street
 * address and exact coordinates are stored for internal use but NEVER pushed to WordPress — only the
 * display name (First + Last initial), the resolved city/county, and the JITTERED coordinates (computed
 * once at capture and stored, never recalculated) reach the public post. The three-field description model
 * (§7) is deliberate: `raw_description` is the tech's immutable input, `source_description` is the
 * operator-editable seed for every AI call, and `enhanced_description` is the overwritten model output.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_captures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();

            $table->string('source')->default('manual');    // JobSource: manual | joby
            $table->string('status')->default('captured');   // JobStatus lifecycle

            $table->ulid('tech_id')->nullable()->index();    // deferred SOFT ref to the capturing tech (§5, Phase 3)

            // Customer — full name is internal only; display name (First + Last initial) is what gets pushed.
            $table->string('client_name_full')->nullable();
            $table->string('client_name_display')->nullable();

            // Address — true address + exact point are internal only, NEVER pushed. Jitter is stored, not recomputed.
            $table->string('address_true')->nullable();
            $table->decimal('lat_true', 10, 7)->nullable();
            $table->decimal('lng_true', 10, 7)->nullable();
            $table->decimal('lat_jittered', 10, 7)->nullable();
            $table->decimal('lng_jittered', 10, 7)->nullable();

            // Geography — resolved from the TRUE address (not the jittered point), by the §4 pipeline.
            $table->foreignUlid('job_city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('job_county_id')->nullable()->constrained()->nullOnDelete();

            // Photos — 3 slots as R2 keys ([{r2_key, hash, alt}]) + the primary (featured) slot index.
            $table->json('photos')->nullable();
            $table->unsignedTinyInteger('primary_photo_index')->default(0);

            // Description — the three-field model (§7). raw is immutable; source seeds AI; enhanced is AI output.
            $table->text('raw_description')->nullable();
            $table->text('source_description')->nullable();
            $table->text('enhanced_description')->nullable();

            // AI-generated (§7) — post title + meta description (photo alt text lives in the photos JSON).
            $table->string('post_title')->nullable();
            $table->string('meta_description', 320)->nullable();

            // Joby (§6) — set when source = joby.
            $table->string('joby_job_id')->nullable()->index();
            $table->string('joby_job_type_raw')->nullable();

            // WordPress (§9) — upsert is matched on the ULID; wp_post_id is stored for the target.
            $table->unsignedBigInteger('wp_post_id')->nullable();
            $table->string('last_publish_error')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['site_id', 'status']);   // the review-queue read path
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_captures');
    }
};
