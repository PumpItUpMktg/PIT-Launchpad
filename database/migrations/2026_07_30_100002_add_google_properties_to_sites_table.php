<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-site pointers into the shared Google grant: WHICH GSC property + GA4 property this tenant's
     * numbers come from. These are non-secret identifiers (a `sc-domain:example.com` / `https://…/`
     * site URL and a `properties/123` id), picked by the operator from the shared account's visible
     * set — the ONLY per-site input the data integration needs. The token itself is platform-wide
     * ({@see google_accounts}); it never sits on the Site.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->string('gsc_property')->nullable()->after('domain_url');
            $table->string('ga4_property')->nullable()->after('gsc_property');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['gsc_property', 'ga4_property']);
        });
    }
};
