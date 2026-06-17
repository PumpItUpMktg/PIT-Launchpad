<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 volume grounding: alongside the existing `volume` aggregate, keep the
 * per-metro breakdown that produced it (transparency — the sum across covered DMAs)
 * and the timestamp of the last grounding run (DataForSEO is paid; re-querying is a
 * deliberate, explicit trigger, never on read).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->json('volume_breakdown')->nullable()->after('volume');
            $table->timestamp('volume_at')->nullable()->after('volume_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('spokes', function (Blueprint $table) {
            $table->dropColumn(['volume_breakdown', 'volume_at']);
        });
    }
};
