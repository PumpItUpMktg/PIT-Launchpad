<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Town Rank: "Collect first" on a card. A whole-site run is 56 pending scans sharing one collector lane,
 * drained round-robin at whatever pace the worker has; the operator rarely needs all of them at once.
 * A pending scan stamped here is dispatched ahead of the rest and the other collectors yield to it, so the
 * keyword the operator cares about lands in minutes instead of hours. Cleared by meaning, not by write:
 * a scan that is no longer pending is no longer prioritized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('town_rank_scans', function (Blueprint $table): void {
            $table->timestamp('collect_first_at')->nullable()->after('scanned_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('town_rank_scans', function (Blueprint $table): void {
            $table->dropIndex(['collect_first_at']);
            $table->dropColumn('collect_first_at');
        });
    }
};
