<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review Capture: `project_date` is the day the WORK was done (distinct from `reviewed_at`, the day the customer
 * wrote the review) — captured on import and on the first-party flow from the completed job. `source_contents`
 * carries an uploaded CSV/XLSX inside the import record so the queued worker can read it: the web node's local
 * disk is not shared with the worker, so a staged file path there was unreadable at import time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->date('project_date')->nullable()->after('reviewed_at');
        });
        Schema::table('review_imports', function (Blueprint $table): void {
            $table->text('source_contents')->nullable()->after('filename'); // CSV text, or base64 XLSX bytes
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn('project_date');
        });
        Schema::table('review_imports', function (Blueprint $table): void {
            $table->dropColumn('source_contents');
        });
    }
};
