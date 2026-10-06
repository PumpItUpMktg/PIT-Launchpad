<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The publish drip (§ Publish drip): per-site override of {enabled, batch, stale_days} — first-time
 * publishes queue and release a batch at a time as the earlier batch gets indexed. See Site::publishDrip().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->json('publish_drip')->nullable()->after('tier_gate');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('publish_drip');
        });
    }
};
