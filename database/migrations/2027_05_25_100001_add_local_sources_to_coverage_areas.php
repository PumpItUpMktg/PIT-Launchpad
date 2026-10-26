<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * § Locations — the official local sources a town page cites (the municipality's own site, the county's,
 * the FEMA flood-map search), resolved once per town and kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->json('local_sources')->nullable();              // list<{key, label, url, verified_at}>
            $table->timestamp('local_sources_resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->dropColumn(['local_sources', 'local_sources_resolved_at']);
        });
    }
};
