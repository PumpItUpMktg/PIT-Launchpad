<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IndexNow submission stamp for a published job — the Job Capture twin of `contents.indexnow_submitted_at`.
 * Set when the job URL is successfully pinged to IndexNow on publish; drives the "Submitted to Bing" pill on
 * the Published Jobs card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->timestamp('indexnow_submitted_at')->nullable()->after('wp_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->dropColumn('indexnow_submitted_at');
        });
    }
};
