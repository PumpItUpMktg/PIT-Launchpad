<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which standard page a Content row IS (StandardPageType value: home / about / why_choose_us / faq /
 * …). The PageType enum is too coarse to tell About from Privacy (both map to Utility), so the
 * standard-page composer resolves a page's kit by this finer identity. Stamped at materialize for
 * BuildSource::Standard pages; null for service/location pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('standard_type')->nullable()->index()->after('page_type');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('standard_type');
        });
    }
};
