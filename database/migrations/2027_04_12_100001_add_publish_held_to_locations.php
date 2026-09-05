<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location PUBLISH-HOLD (location-integrity relay). A held location's pages generate, draft, and pass
 * review normally — only PUBLISHING is blocked, and its URLs are not announced to IndexNow. It is the
 * "hasn't been reviewed yet" gate: a newly imported service area shouldn't publish town pages nobody asked
 * for (the Fallston lesson), so the IMPORT paths set this true; the column DEFAULT is false so the migration
 * never retroactively holds every existing location.
 *
 * Distinct from the advisory `markets.on_hold` (a reminder with no publish effect). "Market" in the operator
 * UI is this Location record (a GBP-anchored service area with an address) — the `Market` model is a separate
 * concept town pages don't reference.
 *
 * Semantics: hold means "don't publish anything NEW here", NOT "unpublish what exists" — already-live pages
 * stay live (an operator takes them down explicitly). So there is no data migration of existing content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->boolean('publish_held')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('publish_held');
        });
    }
};
