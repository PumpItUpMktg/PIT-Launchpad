<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management (§ Citations) — the GLOBAL directory catalog. One table, grown once, reused by every
 * tenant: the durable asset the whole module builds on. NOT site-scoped (no site_id). Attributes here are
 * global; the market-dependent ones (does it rank locally, local SEO value) live on directory_market_signals.
 *
 * seo_value is COMPUTED from objective signals; business_value is OPERATOR-SET — kept as two separate 0–100
 * ratings on purpose (a county chamber can be SEO 15 / Business 80). notes is VA-facing: every rejection
 * becomes a note so the same mistake is never repeated across any tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('domain')->index();
            $table->string('name');
            $table->string('scope')->default('national');            // DirectoryScope: national|state|county|town
            $table->string('geo_value')->nullable();                 // 'NJ' | 'Bergen County' | 'Clifton'
            $table->jsonb('trade_categories')->nullable();           // which trades this directory serves
            $table->unsignedTinyInteger('authority_tier')->default(3); // 1-5, drives scoring weight
            $table->string('submission_method')->nullable();         // SubmissionMethod
            $table->string('submission_url')->nullable();
            $table->decimal('cost_amount', 8, 2)->nullable();
            $table->string('cost_period')->nullable();               // CostPeriod: one_time|annual|monthly
            $table->unsignedSmallInteger('avg_turnaround_days')->nullable();
            $table->boolean('requires_client_action')->default(false);
            $table->string('multi_location_policy')->default('one_per_address'); // MultiLocationPolicy (fails safe)
            $table->string('acquisition_type')->default('free');     // AcquisitionType
            $table->unsignedSmallInteger('effort_minutes')->nullable(); // estimated submission time
            $table->text('ongoing_obligation')->nullable();          // e.g. weekly meeting attendance
            $table->unsignedTinyInteger('domain_rank')->nullable();  // 0-100, DataForSEO domain analytics
            $table->unsignedTinyInteger('seo_value')->nullable();    // 0-100, COMPUTED
            $table->unsignedTinyInteger('business_value')->nullable(); // 0-100, OPERATOR-SET
            $table->boolean('is_nofollow')->default(false);
            $table->text('notes')->nullable();                       // VA-facing quirks/gotchas
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directories');
    }
};
