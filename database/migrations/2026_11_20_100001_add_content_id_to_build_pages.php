<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a manifest entry to the Content row it materialized into. Approve materializes the build
 * manifest (BuildPage rows) into one planned `kind=page` Content each; this FK is the idempotency
 * key — a second materialize reuses the linked Content instead of duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_pages', function (Blueprint $table) {
            $table->foreignUlid('content_id')->nullable()->after('spoke_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('build_pages', function (Blueprint $table) {
            $table->dropColumn('content_id');
        });
    }
};
