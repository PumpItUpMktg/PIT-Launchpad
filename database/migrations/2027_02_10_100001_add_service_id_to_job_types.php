<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture — the job-type vocabulary now mirrors the tenant's Service catalog: a `service`-sourced
 * JobType per Service, keyed by a SOFT `service_id` reference (never a DB FK, so a catalog edit or
 * removal can't cascade into a job's snapshotted types). Native (hand-added) types are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_types', function (Blueprint $table): void {
            $table->ulid('service_id')->nullable()->index()->after('silo_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_types', function (Blueprint $table): void {
            $table->dropColumn('service_id');
        });
    }
};
