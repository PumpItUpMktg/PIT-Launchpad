<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §1 — the per-tenant job-type vocabulary (site-scoped). Two provenances (JobTypeSource):
 * `silo` types are derived from a Sandhog Works tenant's silo structure (silo_id is a SOFT reference —
 * silo regeneration is destructive to the prune arrangement, so the FK is never DB-constrained and the
 * label/slug are what matter); `native` types are a standalone tenant's own list, set at onboarding. The
 * type applied to a job is SNAPSHOTTED onto the job_capture_job_type pivot (label + slug copied), so a job
 * never orphans when a silo regenerates or a type is removed here — this table is the pickable vocabulary,
 * not the system of record for a job's type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('slug');
            $table->ulid('silo_id')->nullable()->index();   // deferred SOFT ref to the originating silo
            $table->string('source')->default('native');     // JobTypeSource: silo | native
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_types');
    }
};
