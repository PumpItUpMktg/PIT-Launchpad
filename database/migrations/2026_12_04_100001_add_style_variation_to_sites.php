<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site's chosen block-theme STYLE VARIATION (Gutenberg pivot). Nullable: null means "use the
 * recommendation" (voice → StyleRecommender), a value is the operator's OVERRIDE — the
 * recommend-with-override model, same governance as auto-arrange. This is the pivot's replacement
 * for a generated brand palette; brand styling is one of the three theme.json variations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('style_variation')->nullable()->after('offers_emergency');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('style_variation');
        });
    }
};
