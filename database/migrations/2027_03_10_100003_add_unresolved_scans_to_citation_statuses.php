<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — how many consecutive scans a citation has stayed in a work-order state without
 * resolving (§ Citations, PR4). Reset to 0 whenever the listing becomes covered; incremented each scan it
 * stays a gap/needs-fix. When it crosses the configured threshold the differ raises a `stalled` event — the
 * escalation signal that a directory needs manual intervention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->unsignedInteger('unresolved_scans')->default(0)->after('mismatch_fields');
        });
    }

    public function down(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->dropColumn('unresolved_scans');
        });
    }
};
