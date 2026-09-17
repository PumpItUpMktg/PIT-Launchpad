<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A town whose result we could never READ is not a town where the site doesn't rank — it is a town we know
 * nothing about, and recording it as "not found" states a fact we never learned.
 *
 * `read_attempts` counts the reads that produced no answer (rate limited, transport, a task the vendor
 * rejects); after the collector's ceiling the point is closed with `read_error` set, so the scan can
 * finalize instead of waiting forever on a task that will never answer, and every surface can colour it
 * apart from a genuine miss.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['town_rank_points', 'geo_grid_points'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedSmallInteger('read_attempts')->default(0)->after('provider_task_id');
                $t->string('read_error')->nullable()->after('read_attempts');
            });
        }
    }

    public function down(): void
    {
        foreach (['town_rank_points', 'geo_grid_points'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn(['read_attempts', 'read_error']);
            });
        }
    }
};
