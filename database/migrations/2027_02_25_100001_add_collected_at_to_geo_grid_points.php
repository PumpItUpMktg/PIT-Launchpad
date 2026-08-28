<?php

use App\Jobs\IngestCoverageScans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo Grid — async collection marker. A coverage scan over a whole county is 100+ towns = 100+ rate-limited
 * DataForSEO task_get calls, which overruns a single job's timeout. The scan is now POSTED by one fast job
 * and its results COLLECTED incrementally by a background sweep ({@see IngestCoverageScans}).
 *
 * A point's `rank` can't tell "collected, business not found (rank null)" from "not collected yet (rank
 * null)". `collected_at` is that discriminator: null = still awaiting its task result; stamped = this cell's
 * DataForSEO task has been read. The sweep finalizes the scan (pending → complete) once every point with a
 * task id has been collected. Purely additive; grid-mode + already-collected scans are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_grid_points', function (Blueprint $table): void {
            $table->timestamp('collected_at')->nullable()->after('provider_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('geo_grid_points', function (Blueprint $table): void {
            $table->dropColumn('collected_at');
        });
    }
};
