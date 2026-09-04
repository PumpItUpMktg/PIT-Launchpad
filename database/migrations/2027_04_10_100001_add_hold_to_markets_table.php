<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market hold (advisory): an operator can put a market on hold with a target release date. It has NO
 * effect on publishing — it is a reminder. The lobby's "held market past its release date" badge fires
 * when `on_hold` is true AND `release_at` has passed (the release is overdue, awaiting a manual lift).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->boolean('on_hold')->default(false)->after('is_covered');
            $table->timestamp('release_at')->nullable()->after('on_hold');
            // The lobby badge filters (on_hold, release_at) across all sites in one grouped query.
            $table->index(['on_hold', 'release_at']);
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropIndex(['on_hold', 'release_at']);
            $table->dropColumn(['on_hold', 'release_at']);
        });
    }
};
