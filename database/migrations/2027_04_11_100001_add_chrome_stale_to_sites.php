<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted chrome-drift flag: true when a site's LIVE header/footer chrome (the last profile pushed to
 * WordPress) no longer matches what SiteProfileAssembler would assemble now — e.g. a service/company page
 * was published or unpublished, or the NAP / nav order changed, without a re-sync (a page republish does
 * NOT re-push chrome). Read cheaply by the Lobby badge; maintained event-driven by ContentObserver on a
 * page publish/unpublish and wholesale by launchpad:check-stale-chrome (the weekly backstop, for the drift
 * that isn't a page publish — NAP / nav_order edits). Never-synced is a separate signal (chrome_synced_at
 * null), not this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('chrome_stale')->default(false)->after('chrome_synced_hash');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('chrome_stale');
        });
    }
};
