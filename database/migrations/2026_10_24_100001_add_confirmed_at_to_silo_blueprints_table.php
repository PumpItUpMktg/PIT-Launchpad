<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — the prune. A blueprint is `confirmed` only once every non-fringe candidate
 * spoke has an owner decision (select/veto/route); the timestamp records when the
 * directed-coverage layer was locked in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
