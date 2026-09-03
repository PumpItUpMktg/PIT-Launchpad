<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the operator portfolio (`/admin/sites`, SiteResource) health counts. That list runs four correlated
 * `withCount` subqueries against `contents` per tenant — `where site_id = ? and status = ?` (needs_review /
 * render_failed / publish_failed) plus one adding `published_at >=` (published this week) — and then SORTS by a
 * subquery alias, which forces all of them to materialize for every row. `contents` only had separate
 * single-column `site_id` and (low-selectivity) `status` indexes, so each count scanned a tenant's rows and
 * filtered status four times over. On a populated `contents` table that made the page take 6–7 s and time the
 * FastCGI upstream out (504). One composite `(site_id, status, published_at)` index serves all four: the
 * count-only three use the `(site_id, status)` prefix, the published-this-week one uses the full key.
 *
 * Built with CREATE INDEX CONCURRENTLY on Postgres so it does NOT lock writes on the hot `contents` table while
 * it builds — which requires running OUTSIDE a transaction (`$withinTransaction = false`). The SQLite test
 * driver has no CONCURRENTLY, so it gets a plain index through the schema builder.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    private const NAME = 'contents_site_status_published_idx';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('contents', function (Blueprint $table): void {
                $table->index(['site_id', 'status', 'published_at'], self::NAME);
            });

            return;
        }

        // Idempotent + deploy-safe. If the index already exists (the live platform built it on a prior run
        // or out-of-band), emit NO statement and just let the migration record. Laravel Cloud's deploy
        // migrate does NOT honor $withinTransaction=false, so a re-run of `CREATE INDEX CONCURRENTLY` is
        // rejected ("cannot run inside a transaction block") BEFORE `IF NOT EXISTS` is even evaluated — which
        // failed every deploy while this migration sat pending against a DB that already had the index.
        // Skipping when present records it cleanly; a genuinely fresh Postgres env still builds it concurrently.
        if ($this->pgIndexExists()) {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON contents (site_id, status, published_at)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Plain DROP (not CONCURRENTLY) so a rollback is transaction-safe; dropping needs no concurrency.
            DB::statement('DROP INDEX IF EXISTS '.self::NAME);

            return;
        }

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropIndex(self::NAME);
        });
    }

    private function pgIndexExists(): bool
    {
        return DB::selectOne('SELECT 1 FROM pg_class WHERE relname = ?', [self::NAME]) !== null;
    }
};
