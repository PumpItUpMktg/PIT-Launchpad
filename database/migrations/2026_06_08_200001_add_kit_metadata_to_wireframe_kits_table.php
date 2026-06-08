<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wireframe_kits', function (Blueprint $table) {
            $table->string('page_type')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('elementor_template_ref')->nullable();
            $table->string('seo_profile_ref')->nullable();

            // One kit per (tenant, page type, version); library kits use null site_id.
            $table->unique(['site_id', 'page_type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('wireframe_kits', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'page_type', 'version']);
            $table->dropColumn(['page_type', 'version', 'elementor_template_ref', 'seo_profile_ref']);
        });
    }
};
