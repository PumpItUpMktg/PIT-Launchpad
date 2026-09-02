<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant Review Capture settings (§6). `review_body_min_length` overrides the config default for the public
 * submission form's minimum body length; `review_reminders_enabled` toggles the day-3/day-10 reminder sends for
 * a tenant. Both additive and nullable/defaulted — an unset tenant falls back to config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->unsignedInteger('review_body_min_length')->nullable()->after('budget_ceiling');
            $table->boolean('review_reminders_enabled')->default(true)->after('review_body_min_length');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['review_body_min_length', 'review_reminders_enabled']);
        });
    }
};
