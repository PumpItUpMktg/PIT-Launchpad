<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant override for the tiered-rollout gate thresholds (indexed_pct / stale_days). Nullable JSON,
 * merged over the platform defaults by {@see Site::tierGate()} — mirrors `coverage_thresholds`.
 * Absent (null) means "use config defaults", so existing tenants need no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->json('tier_gate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('tier_gate');
        });
    }
};
