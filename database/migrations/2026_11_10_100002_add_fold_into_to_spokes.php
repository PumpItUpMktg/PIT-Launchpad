<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a folded spoke lands: the core page (spoke) it folds into as a longtail section.
 * Null = its silo pillar (the default for a sub-bar core spoke). Set by the prune's
 * most-related-core default; the operator can redirect. Generation reads it to place the
 * section/anchor (§3). A folded spoke is absorbed, never dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->string('fold_into_id')->nullable()->after('granularity');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn('fold_into_id');
        });
    }
};
