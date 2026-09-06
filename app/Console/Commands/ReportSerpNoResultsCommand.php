<?php

namespace App\Console\Commands;

use App\Enums\SerpTaskState;
use App\Models\Keyword;
use App\Models\SerpTask;
use Illuminate\Console\Command;

/**
 * Report (read-only): SERP "No Search Results" (40102) queries — the dead queries DataForSEO ran and
 * Google returned nothing for, because the query isn't a searchable term (a taxonomy label that leaked
 * into the keyword set). These are the source of the wasted task spend (billing is on submission), and
 * the dispatcher now refuses to re-post them.
 *
 * `serp_tasks` carries no `site_id` (the cache key is keyed on query, not tenant), so each dead query is
 * attributed back to the tenant(s) whose §5 keyword set still contains it — surfacing exactly which
 * Keyword rows to clean (the §5 label-demotion relay). READ-ONLY, live-only.
 */
class ReportSerpNoResultsCommand extends Command
{
    protected $signature = 'launchpad:report-serp-no-results
        {--limit=100 : Max distinct dead queries to list}';

    protected $description = 'Report (read-only) SERP 40102 "No Search Results" queries and the keyword rows behind them.';

    public function handle(): int
    {
        $tasks = SerpTask::query()
            ->where('state', SerpTaskState::NoResults->value)
            ->get(['function', 'query']);

        if ($tasks->isEmpty()) {
            $this->info('No SERP tasks in the no_results (40102) state. Nothing dead to report.');

            return self::SUCCESS;
        }

        $this->info('Read-only · live-only · SERP 40102 "No Search Results" queries (dead — never re-posted).');
        $this->line($tasks->count().' no_results task(s) across '.$tasks->pluck('query')->unique()->count().' distinct query(ies).');

        // Attribute each dead query back to the tenants whose keyword set still carries it (case-insensitive).
        $keywordsByQuery = $this->keywordsByQuery($tasks->pluck('query')->unique()->all());

        $limit = max(1, (int) $this->option('limit'));
        foreach ($tasks->pluck('query')->unique()->take($limit) as $query) {
            $owners = $keywordsByQuery[$this->norm((string) $query)] ?? [];
            $this->newLine();
            $this->line("<info>\"{$query}\"</info>");
            if ($owners === []) {
                $this->line('  · no live keyword row (already cleaned, or another tenant\'s)');

                continue;
            }
            foreach ($owners as $owner) {
                $this->line("  · {$owner['tenant']} — keyword {$owner['keyword_id']}, status {$owner['status']}"
                    .($owner['silo'] !== null ? ", silo \"{$owner['silo']}\"" : ''));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Map each normalized dead query → the live Keyword rows (any tenant) that still carry it.
     *
     * @param  list<string>  $queries
     * @return array<string, list<array{tenant: string, keyword_id: string, status: string, silo: ?string}>>
     */
    private function keywordsByQuery(array $queries): array
    {
        $normalized = array_values(array_unique(array_map(fn (string $q): string => $this->norm($q), $queries)));
        if ($normalized === []) {
            return [];
        }

        $out = [];
        Keyword::withoutGlobalScopes() // cross-tenant operator report: drop SiteScope AND VisibleTenantScope
            ->with(['site:id,brand_name', 'silo:id,name'])
            ->whereRaw('lower(trim(query)) in ('.implode(',', array_fill(0, count($normalized), '?')).')', $normalized)
            ->get()
            ->each(function (Keyword $k) use (&$out): void {
                $out[$this->norm((string) $k->query)][] = [
                    'tenant' => $k->site->brand_name ?? '—',
                    'keyword_id' => (string) $k->id,
                    'status' => (string) $k->status,
                    'silo' => $k->silo?->name,
                ];
            });

        return $out;
    }

    private function norm(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
