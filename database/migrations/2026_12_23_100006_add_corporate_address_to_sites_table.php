<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // The corporate / site-wide street address — the "A" of the NAP for the ENTIRE site,
            // captured from the business intake and shown in the header/footer chrome. This is distinct
            // from any physical Location's own address (a multi-location tenant's location pages keep
            // their own NAP). Structured so it can format cleanly and feed schema. Corporate phone is
            // already sites.phone (the canonical business number).
            $table->string('corporate_street')->nullable()->after('emergency_phone');
            $table->string('corporate_city')->nullable()->after('corporate_street');
            $table->string('corporate_state')->nullable()->after('corporate_city');
            $table->string('corporate_postal_code')->nullable()->after('corporate_state');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['corporate_street', 'corporate_city', 'corporate_state', 'corporate_postal_code']);
        });
    }
};
