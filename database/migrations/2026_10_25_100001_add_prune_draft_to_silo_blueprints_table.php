<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 PR-B: the prune UI persists the operator's in-progress decision-set so the
 * walkthrough survives across sittings (mirrors the interview's draft/resume). Finalize
 * applies it through the PruneEngine and clears the draft; confirmed_at is the commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->json('prune_draft')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->dropColumn('prune_draft');
        });
    }
};
