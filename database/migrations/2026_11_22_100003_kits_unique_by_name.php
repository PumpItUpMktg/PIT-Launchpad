<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make a kit's identity its NAME, not its page_type. The standard-page composer adds several kits
 * that share page_type='utility' (about / why-choose-us / faq), which the old
 * unique(site_id, page_type, version) forbade. `name` is the natural kit identity anyway
 * (service-page, location-page, about-page, …); service/location resolution by page_type is
 * unaffected because each still has exactly one kit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireframe_kits', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'page_type', 'version']);
            $table->unique(['site_id', 'name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('wireframe_kits', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'name', 'version']);
            $table->unique(['site_id', 'page_type', 'version']);
        });
    }
};
