<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stop the generated-feed cross-product from keeping two ENABLED feeds pointed at the same Google-News
 * search. A PARTIAL unique index on (site_id, url) WHERE enabled — partial so it constrains only live feeds,
 * which lets retirement stay DEACTIVATION (never deletion): disabled duplicates keep their rows + provenance
 * for already-attributed candidates, and only ONE per URL may be enabled at a time.
 *
 * Existing enabled duplicates are first collapsed non-destructively — per (site_id, url) group the best row
 * is kept enabled (a producer first, else the oldest) and the rest are DISABLED (not deleted) — so the index
 * can be created. Idempotent + deploy-safe (IF NOT EXISTS).
 */
return new class extends Migration
{
    private const NAME = 'sources_site_url_enabled_uidx';

    public function up(): void
    {
        // Collapse existing enabled duplicates: keep one per (site_id, url), disable the rest.
        $groups = DB::table('sources')
            ->where('enabled', true)
            ->whereNotNull('url')
            ->select('site_id', 'url')
            ->groupBy('site_id', 'url')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $ids = DB::table('sources')
                ->where('enabled', true)
                ->where('site_id', $group->site_id)
                ->where('url', $group->url)
                ->orderByRaw('(last_item_at is null) asc') // producers (not-null) first
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $disable = $ids->slice(1)->all(); // keep the first, disable the rest
            if ($disable !== []) {
                DB::table('sources')->whereIn('id', $disable)->update(['enabled' => false]);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::NAME.' ON sources (site_id, url) WHERE enabled = true');
        } else {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::NAME.' ON sources (site_id, url) WHERE enabled = 1');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::NAME);
    }
};
