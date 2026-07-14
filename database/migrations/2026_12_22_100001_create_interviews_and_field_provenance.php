<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gathering relay (new Setup, steps 1–6): the adaptive owner-interview transcript store and the
 * field-provenance sidecar.
 *
 * - `interviews` + `interview_turns`: the transcript is the permanent source of truth —
 *   extraction re-runs against it at any time. One row per turn (assistant question / operator-
 *   typed owner answer / operator note), each assistant turn tagged with the section goal it
 *   probes; the interview row carries the live coverage self-assessment.
 * - `field_provenances`: a sidecar (deliberately NOT columns on every seedable table) mapping
 *   (model, field) → seeded|confirmed. Extraction writes `seeded`; an operator save on a review
 *   surface flips to `confirmed`; extraction never overwrites a confirmed field. Manually
 *   entered fields simply have no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('in_progress'); // InterviewStatus
            $table->json('coverage')->nullable();             // section => filled|thin|empty (model self-assessment)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });

        Schema::create('interview_turns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('interview_id')->constrained()->cascadeOnDelete();
            $table->string('role');                    // assistant | operator
            $table->text('content');
            $table->string('section_tag')->nullable(); // InterviewSection (assistant turns; operator notes may carry one)
            $table->timestamps();

            $table->index('interview_id');
        });

        Schema::create('field_provenances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->string('model_id');
            $table->string('field');
            $table->string('state'); // ProvenanceState: seeded | confirmed
            $table->timestamps();

            $table->unique(['model_type', 'model_id', 'field']);
            $table->index(['site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_provenances');
        Schema::dropIfExists('interview_turns');
        Schema::dropIfExists('interviews');
    }
};
