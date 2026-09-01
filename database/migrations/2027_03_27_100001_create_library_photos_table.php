<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable per-account Job Capture photo library (§ Job Capture). An operator uploads their stock of job
 * photos once; each can then be attached to many jobs, and every attachment gets its OWN geotagged copy under
 * the job's R2 prefix — so one library image legitimately carries a different job's approximate location. The
 * library row is the source original; `hash` dedupes identical re-uploads within an account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_photos', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->ulid('created_by_user_id')->nullable(); // soft ref — who uploaded it

            $table->string('r2_key');                 // the source original in R2 (account/library prefix)
            $table->string('hash');                   // sha256 of the bytes — dedupe within the account
            $table->string('original_filename')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('byte_size')->nullable();

            $table->jsonb('tags')->nullable();        // freeform labels for "find a similar pic" filtering
            $table->string('label')->nullable();      // optional human caption

            $table->timestamps();
            $table->softDeletes();

            $table->index(['account_id', 'hash']);    // dedupe lookups + account listing
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_photos');
    }
};
