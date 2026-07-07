<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Your brand colors" style choice. It can't live in `style_variation` (that casts to the
 * StyleVariation enum, whose exhaustive matches only know bold/clean/warm), so the logo-derived
 * variation is a separate flag: when set, StyleActivator builds + pushes the per-tenant dynamic
 * variation from the logo colors instead of a curated one. Off by default — the recommendation stays
 * voice-driven; this is an override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('use_logo_colors')->default(false)->after('style_variation');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('use_logo_colors');
        });
    }
};
