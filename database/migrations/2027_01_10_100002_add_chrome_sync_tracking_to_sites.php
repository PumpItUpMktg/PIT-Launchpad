<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last header/footer chrome push so surfaces can tell an operator when the live menu is
 * STALE. The chrome is site-wide and pushed only by "Sync header & footer" (never on a page publish or
 * a nav_featured/nav_order toggle), so it silently drifts from the control-plane data. We stamp the
 * time of the last successful push plus a fingerprint of the profile that was pushed; comparing that
 * fingerprint to the freshly-assembled profile answers "does this need re-syncing?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->timestamp('chrome_synced_at')->nullable()->after('weather_alert');
            $table->string('chrome_synced_hash')->nullable()->after('chrome_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['chrome_synced_at', 'chrome_synced_hash']);
        });
    }
};
