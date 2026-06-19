<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * auto-arrange decision provenance — the CoverageWriter `source` lesson applied to the
 * silo taxonomy: every structural decision auto-arrange makes (fold target, later
 * sub-hub parent + primary keyword) is marked `auto` (a re-run may overwrite it) or
 * `confirmed` (operator accepted/dismissed it — preserved across re-runs). Without this
 * the decision-preservation twin can't tell what it may safely overwrite. `arrangement_score`
 * persists the cosine/overlap behind the decision so the thresholds can be tuned from
 * live output rather than guessed. Both null until auto-arrange first touches the spoke.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->string('arrangement_source')->nullable()->after('fold_into_id');
            $table->double('arrangement_score')->nullable()->after('arrangement_source');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn(['arrangement_source', 'arrangement_score']);
        });
    }
};
