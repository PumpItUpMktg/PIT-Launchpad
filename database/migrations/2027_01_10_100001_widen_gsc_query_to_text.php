<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GSC returns some very long "queries" — AI-overview / conversational strings
 * (one observed at ~900 chars) — that overflow the original varchar(512),
 * crashing the backfill with SQLSTATE[22001] "value too long". Widen `query` to
 * an unbounded `text` column on both grain tables.
 *
 * The `(site_id, query)` btree index is dropped in the same move: a btree entry
 * over ~2704 bytes fails on INSERT ("index row size exceeds maximum"), which a
 * long query can hit, and the reporting reads (distinct-query counts, banded
 * top-3/10/20) group by query via a hash aggregate over a (site_id, date)-scoped
 * range — they don't need this index. If exact-query lookups ever need one, add
 * a hashed `query_hash` index rather than indexing the raw text.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['gsc_url_query_daily', 'gsc_url_query_monthly'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropIndex(['site_id', 'query']);
            });
            Schema::table($table, function (Blueprint $t): void {
                $t->text('query')->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['gsc_url_query_daily', 'gsc_url_query_monthly'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('query', 512)->change();
            });
            Schema::table($table, function (Blueprint $t): void {
                $t->index(['site_id', 'query']);
            });
        }
    }
};
