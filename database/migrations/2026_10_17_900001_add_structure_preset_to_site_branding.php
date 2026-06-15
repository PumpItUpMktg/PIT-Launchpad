<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand System Phase 2: the chosen structure preset (trust|bold|warm) per tenant.
 * Drives the body.wf-structure-{slug} class + token bundle on native pages. Nullable
 * — the companion + the engine both default to 'trust' when unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_branding', function (Blueprint $table) {
            $table->string('structure_preset')->nullable()->after('typography');
        });
    }

    public function down(): void
    {
        Schema::table('site_branding', function (Blueprint $table) {
            $table->dropColumn('structure_preset');
        });
    }
};
