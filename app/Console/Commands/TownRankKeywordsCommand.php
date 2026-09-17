<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Support\SiteFinder;
use Illuminate\Console\Command;

/**
 * Where the Town Rank / Service Areas keyword wall's cards came from.
 *
 * "34 keywords are showing — who put them there?" Nothing records the hand that set a flag, so this reports
 * the EVIDENCE rather than inventing an author: which of the two membership flags is on (`track_town_rank`,
 * set by "Add keyword" on the wall and by the 2027_04_27 backfill of everything already scanned;
 * `is_grid_keyword`, set by the coverage plan's "add top-level services" and the keyword resource), how the
 * keyword entered the site at all (`KeywordSource`), when the row was created, and how many town-rank scans
 * it actually carries. Read-only.
 */
class TownRankKeywordsCommand extends Command
{
    protected $signature = 'launchpad:town-rank-keywords {site : Site id, brand name, or domain (partial ok)}';

    protected $description = 'List the keywords on the Town Rank wall with the flags, source and scans that put them there.';

    public function handle(): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        // Aggregates, so read the base rows — the model has no `scans`/`last_scan` attribute to speak of.
        $scans = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->selectRaw('keyword_id, count(*) as scans, max(scanned_at) as last_scan')
            ->groupBy('keyword_id')
            ->toBase()
            ->get()
            ->keyBy(fn (object $s): string => (string) $s->keyword_id);

        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('track_town_rank', true)->orWhere('is_grid_keyword', true))
            ->orderByDesc('priority')
            ->orderBy('query')
            ->get();

        $this->info("{$site->brand_name} — {$keywords->count()} keyword(s) on the wall");

        $rows = [];
        $counts = ['both' => 0, 'tracked' => 0, 'grid' => 0];
        foreach ($keywords as $keyword) {
            $scan = $scans->get((string) $keyword->id);
            $tracked = (bool) $keyword->track_town_rank;
            $grid = (bool) $keyword->is_grid_keyword;
            $counts[$tracked && $grid ? 'both' : ($tracked ? 'tracked' : 'grid')]++;
            $rows[] = [
                $keyword->query,
                (int) $keyword->priority,
                $tracked ? 'yes' : '—',
                $grid ? 'yes' : '—',
                $keyword->source->value,
                $keyword->created_at?->toDateString() ?? '—',
                $scan !== null ? (int) $scan->scans : 0,
                $scan !== null && $scan->last_scan !== null ? (string) $scan->last_scan : 'never',
            ];
        }
        $this->table(['keyword', 'priority', 'town rank', 'geo grid', 'source', 'created', 'scans', 'last scan'], $rows);

        $this->line(sprintf('Flags: %d both · %d town-rank only · %d geo-grid only.', $counts['both'], $counts['tracked'], $counts['grid']));
        $this->line('geo-grid only = flagged by the coverage plan (one keyword per top-level service page), never added on the wall.');

        // What the flags exclude: keywords that were scanned once and have since been removed from the wall.
        $orphanIds = $scans->keys()->map(fn ($id): string => (string) $id)
            ->diff($keywords->map(fn (Keyword $k): string => (string) $k->id))->all();
        if ($orphanIds !== []) {
            $this->line(sprintf('%d keyword(s) hold town-rank scans but are off the wall (removed) — their data is kept.', count($orphanIds)));
        }

        return self::SUCCESS;
    }

    private function site(): ?Site
    {
        $needle = (string) $this->argument('site');
        $matches = SiteFinder::matches($needle);
        if ($matches->isEmpty()) {
            $this->error("No site matches [{$needle}].");

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("[{$needle}] is ambiguous — it matches {$matches->count()} sites. Re-run with the id.");

            return null;
        }

        /** @var Site $site */
        $site = $matches->first();

        return $site;
    }
}
