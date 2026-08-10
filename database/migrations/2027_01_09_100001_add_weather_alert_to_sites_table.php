<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant opt-in for the severe-weather (rain) alert bar the companion header renders. The bar was
 * previously auto-enabled from the tenant's trade keywords, so every rain-relevant trade (plumbing,
 * waterproofing, …) showed it. This flag makes it an explicit operator choice — default OFF — so the
 * bar is exclusive to the tenants an operator turns it on for. Read by SiteProfileAssembler and pushed
 * in the site profile; toggled from the Console → Corrections page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('weather_alert')->default(false)->after('offers_emergency');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('weather_alert');
        });
    }
};
