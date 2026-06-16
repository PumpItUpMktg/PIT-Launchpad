<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the raw owner-interview conversation alongside the seed it produced, so the
 * seed can be re-extracted if the extractor improves without re-interviewing the
 * owner (PR #2 of the silo-generator arc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->json('transcript')->nullable()->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('silo_blueprints', function (Blueprint $table) {
            $table->dropColumn('transcript');
        });
    }
};
