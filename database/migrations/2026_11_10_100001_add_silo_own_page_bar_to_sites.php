<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site override for the silo own-page bar (the volume floor at/above which a core spoke
 * pre-checks for its own page in the simplified prune). Null = the platform default
 * (config launchpad.silo_volume.fold_threshold). One knob, per-site tunable from live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('silo_own_page_bar')->nullable()->after('budget_ceiling');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('silo_own_page_bar');
        });
    }
};
