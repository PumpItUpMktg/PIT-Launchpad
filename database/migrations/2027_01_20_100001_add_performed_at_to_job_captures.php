<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the job was actually performed. A tech capture is "now", so this stayed implicit in created_at — but
 * an operator BACKFILLING a previous job needs to record its real date (it drives the published post's
 * date, not when it was entered). Nullable: capture-time jobs leave it unset and fall back to created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->date('performed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('job_captures', function (Blueprint $table): void {
            $table->dropColumn('performed_at');
        });
    }
};
