<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-hub node (auto-arrange Pass C). A silo can be demoted to a *sub-hub* under another
 * silo: its pillar is preserved as a child hub page (`is_sub_hub`) with its spokes still
 * nested beneath it, distinct from the dissolve-style "fold silo into". `parent_silo_id`
 * is the parent silo's pillar spoke id, carried on the (sub-hub) pillar. Its provenance
 * rides on the existing `arrangement_source` (auto-recommended vs operator-confirmed), so
 * a confirmed demotion survives a re-run. One level deep only (pillar → sub-hub → leaf).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->string('parent_silo_id')->nullable()->after('fold_into_id');
            $table->boolean('is_sub_hub')->default(false)->after('parent_silo_id');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn(['parent_silo_id', 'is_sub_hub']);
        });
    }
};
