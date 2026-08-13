<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral mode (per service): a service the tenant publishes content about but REFERS rather than performs
 * — no price range, no warranty, no `Service` schema declaring the tenant as provider, and a referral CTA
 * instead of a quote request. Defaults false, so every existing service is unchanged. The tenant-level
 * referral CTA (label + destination URL) lives on the conversion config so it's set once per tenant and
 * reused across all referral-mode services.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('referral_mode')->default(false)->after('warranty_applicable');
        });

        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->string('referral_cta_label')->nullable();
            $table->string('referral_cta_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('referral_mode');
        });

        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->dropColumn(['referral_cta_label', 'referral_cta_url']);
        });
    }
};
