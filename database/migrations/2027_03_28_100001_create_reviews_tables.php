<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review Capture §5 — the data model. `reviews` holds captured first-party + imported reviews through the
 * approval lifecycle; `review_service` tags a review to the tenant's Services (silo structure); `review_requests`
 * is the outbound solicitation with its single-use token and the CompletedJob payload snapshot.
 *
 * Tenancy is `site_id` (this platform's tenant is a Site). `service_address` is internal audit only — never
 * rendered below city. Uniqueness is enforced at the DB level: at most one request row per (site_id, external_ref)
 * when external_ref is present, so a future redelivering driver (Joby) can't double-issue — a partial unique
 * index, valid on both Postgres and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            $table->foreignUlid('location_id')->nullable()->index(); // null => needs_location
            $table->string('external_ref')->nullable();

            $table->string('source')->default('first_party');   // ReviewSource
            $table->string('import_source')->nullable();          // google | facebook | angi | manual | ...
            $table->string('status')->default('pending')->index(); // ReviewStatus

            $table->unsignedTinyInteger('rating');                // 1-5
            $table->text('body');
            $table->string('customer_name');                      // display form: "First L."
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('service_address')->nullable();        // internal audit only, never rendered

            $table->timestamp('reviewed_at');                     // the review's own date (import preserves original)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();              // soft ref to users
            $table->timestamp('published_at')->nullable();

            $table->boolean('needs_location')->default(false)->index();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index(['location_id', 'status']);
        });

        Schema::create('review_service', function (Blueprint $table): void {
            $table->foreignUlid('review_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('service_id')->index();           // soft ref to services (silo rebuild-safe)
            $table->timestamps();

            $table->primary(['review_id', 'service_id']);         // one tag per (review, service)
        });

        Schema::create('review_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->index();
            $table->foreignUlid('review_id')->nullable();         // set on submission
            $table->string('token')->unique();                    // sha-256 hash of the single-use token
            $table->string('external_ref')->nullable();
            $table->jsonb('payload');                             // the CompletedJob snapshot

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamps();
        });

        // One request per (site_id, external_ref) when external_ref is present — the DB-level guard against a
        // redelivering upstream double-issuing. Partial unique index; identical syntax on Postgres and SQLite.
        DB::statement('CREATE UNIQUE INDEX review_requests_site_external_unique ON review_requests (site_id, external_ref) WHERE external_ref IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('review_requests');
        Schema::dropIfExists('review_service');
        Schema::dropIfExists('reviews');
    }
};
