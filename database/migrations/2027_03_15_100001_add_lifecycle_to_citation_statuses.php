<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the submit→verify lifecycle counters (§ Citations, PR7). `submitted_at` records when a
 * VA (or an operator's manual-submit) reported the listing done; `verification_cycles` counts how many scan
 * passes have failed to confirm it live (→ unverified at the threshold); `work_order_count` counts how many
 * work orders a citation has been issued in (→ stalled at the threshold).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('unresolved_scans');
            $table->unsignedInteger('verification_cycles')->default(0)->after('submitted_at');
            $table->unsignedInteger('work_order_count')->default(0)->after('verification_cycles');
            $table->string('reject_reason')->nullable()->after('work_order_count');
        });
    }

    public function down(): void
    {
        Schema::table('citation_statuses', function (Blueprint $table): void {
            $table->dropColumn(['submitted_at', 'verification_cycles', 'work_order_count', 'reject_reason']);
        });
    }
};
