<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a URL FIRST reached the index. `last_inspected_at` moves on every re-inspection, so it cannot say
 * how long a page has been indexed — and the Indexing board's watchlist needs exactly that to let a page
 * fall off a few days after it lands. Stamped by the index sync the first time a verdict reads PASS,
 * cleared if a later inspection drops it, re-stamped when it returns.
 *
 * Backfill: an existing PASS row is stamped with the row's creation date — the earliest we know it was in
 * the index — so long-indexed pages fall straight off the watchlist rather than every one of them showing
 * as "just indexed" on the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_index_states', function (Blueprint $table): void {
            $table->timestamp('indexed_at')->nullable()->after('last_inspected_at');
        });

        DB::table('page_index_states')->where('index_verdict', 'PASS')->update(['indexed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('page_index_states', function (Blueprint $table): void {
            $table->dropColumn('indexed_at');
        });
    }
};
