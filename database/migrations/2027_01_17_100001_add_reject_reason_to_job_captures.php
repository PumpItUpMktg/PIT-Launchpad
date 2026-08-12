<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §8 — the operator's rejection note. When a reviewed job is rejected, the reason is recorded
 * so the tech / operator can see why (mirrors §6c's `Content.reject_reason`). Cleared when a job is
 * re-approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->text('reject_reason')->nullable()->after('last_publish_error');
        });
    }

    public function down(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->dropColumn('reject_reason');
        });
    }
};
