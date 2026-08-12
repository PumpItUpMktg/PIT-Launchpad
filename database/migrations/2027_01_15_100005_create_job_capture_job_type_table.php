<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §1 — a job's applied job types (max 3, enforced in the model). This is the SNAPSHOT: the
 * label + slug are copied here at capture time, and `job_type_id` is a SOFT reference back to the
 * job_types vocabulary (nullable, un-constrained) so a job keeps its type even after a silo regenerates or
 * the vocabulary row is removed — the same "copy the label so the link survives a rebuild" rationale as
 * content_towns. Unique on (job, slug) so a type can't be applied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_capture_job_type', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('job_capture_id')->constrained()->cascadeOnDelete();
            $table->ulid('job_type_id')->nullable()->index();   // SOFT ref to job_types (may be gone after regen)
            $table->string('label');   // snapshot — the human label at capture time
            $table->string('slug');     // snapshot — the slug at capture time
            $table->timestamps();

            $table->unique(['job_capture_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_capture_job_type');
    }
};
