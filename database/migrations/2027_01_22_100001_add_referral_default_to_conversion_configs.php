<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-level referral default: when true, a NEWLY created Service defaults to referral_mode (the tenant
 * refers rather than performs). Seeds the per-service flag at creation only — it never locks, overrides, or
 * retroactively changes a service (the per-service flag stays authoritative and independently editable).
 * Defaults false, so every existing tenant is unchanged. Lives on the conversion config beside the referral
 * CTA, since the two are used together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->boolean('referral_default')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('conversion_configs', function (Blueprint $table): void {
            $table->dropColumn('referral_default');
        });
    }
};
