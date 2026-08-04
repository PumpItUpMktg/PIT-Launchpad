<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The site's verified Bing Webmaster Tools site URL — the `siteUrl` the agency BWT API key reads for
 * this tenant (the Bing twin of `sites.gsc_property`). Nullable: null = Bing not wired for this site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('bing_site_url')->nullable()->after('gsc_property');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('bing_site_url');
        });
    }
};
