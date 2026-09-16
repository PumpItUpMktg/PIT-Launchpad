<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Support\SiteFinder;
use App\TownRank\TownRankScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Town Rank repair: re-read the PHANTOM towns of a site's scans — towns a collector run before the
 * transient-read fix (#867) recorded as "not found" with no results stored, because the read itself
 * failed (rate-limited) and was mistaken for Google's answer. Report-first: the default run counts the
 * phantoms per scan and spends nothing; --execute re-reads them from their stored task ids (reads are
 * free — no new posting) and writes the real rank. A read that fails again is left as it was and
 * counted; re-run to pick those up. Paced to finish inside a hosted Commands panel's window.
 */
class TownRankRecollectCommand extends Command
{
    protected $signature = 'launchpad:town-rank-recollect {site : Site id, brand name, or domain (partial ok)}
        {--keyword= : One keyword (id or exact query, else substring); default: every keyword with a scan}
        {--execute : Re-read the phantom towns now (free reads) and write the real ranks}
        {--minutes=25 : With --execute: stop after this many minutes and report; re-run for the rest}';

    protected $description = 'Re-read Town Rank towns recorded as "not found" by a failed read (no results stored) from their existing DataForSEO tasks — report by default, --execute to repair. Free.';

    public function handle(TownRankScanner $scanner): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }

        $scans = $this->scans($site);
        if ($scans->isEmpty()) {
            $this->info('No Town Rank scans for this site'.(trim((string) $this->option('keyword')) !== '' ? ' and keyword' : '').'.');

            return self::SUCCESS;
        }

        $rows = [];
        $totalPhantoms = 0;
        $work = [];
        foreach ($scans as $scan) {
            $phantoms = TownRankScanner::phantoms($scan);
            $towns = $scan->points()->count();
            $notFound = $scan->points()->whereNotNull('collected_at')->whereNull('rank')->count();
            $rows[] = [
                $scan->keyword->query ?? (string) $scan->keyword_id,
                $scan->mode === 'town_query' ? 'town search' : 'from town',
                $scan->status,
                $scan->scanned_at?->toDateTimeString() ?? '—',
                $towns,
                (int) $scan->found_count,
                $notFound,
                $phantoms->count(),
            ];
            $totalPhantoms += $phantoms->count();
            if ($phantoms->isNotEmpty()) {
                $work[] = [$scan, $phantoms];
            }
        }

        $this->table(['Keyword', 'Mode', 'Status', 'Scanned', 'Towns', 'Ranked', 'Not found', 'Phantom (no results stored)'], $rows);
        $this->line("<info>Phantom towns</info>: {$totalPhantoms} across {$scans->count()} scan(s) — \"not found\" recorded with no results at all: a failed read, not Google's answer.");

        if ($totalPhantoms === 0) {
            return self::SUCCESS;
        }
        if (! (bool) $this->option('execute')) {
            $this->comment('Read-only. Re-run with --execute to re-read them from their existing tasks (free) and write the real ranks.');

            return self::SUCCESS;
        }

        $deadline = microtime(true) + max(1, (int) $this->option('minutes')) * 60;
        $totals = ['read' => 0, 'ranked' => 0, 'not_found' => 0, 'skipped' => 0];
        $this->newLine();
        foreach ($work as [$scan, $phantoms]) {
            if (microtime(true) >= $deadline) {
                $this->warn('Time budget spent — re-run to continue with the remaining scans.');
                break;
            }
            $label = ($scan->keyword->query ?? (string) $scan->keyword_id).' · '.($scan->mode === 'town_query' ? 'town search' : 'from town');
            $this->line("Re-reading {$phantoms->count()} town(s) for <info>{$label}</info>…");
            try {
                $r = $scanner->recollect($scan, $phantoms, $deadline);
            } catch (Throwable $e) {
                $this->error("  stopped: {$e->getMessage()}");

                return self::FAILURE;
            }
            foreach ($r as $k => $v) {
                $totals[$k] += $v;
            }
            $left = $phantoms->count() - $r['read'];
            $this->line(sprintf('  read %d · ranked %d · not found %d · unreadable %d%s', $r['read'], $r['ranked'], $r['not_found'], $r['skipped'], $left > 0 ? " · {$left} not reached (time)" : ''));
        }

        $this->newLine();
        $this->line(sprintf('<info>Done</info>: read %d · ranked %d · not found (real) %d · unreadable %d', $totals['read'], $totals['ranked'], $totals['not_found'], $totals['skipped']));
        if ($totals['skipped'] > 0) {
            $this->comment('Unreadable towns were left exactly as they were — re-run later; if they never read, the task results have expired at DataForSEO and only a fresh scan can fill them.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, TownRankScan> */
    private function scans(Site $site): Collection
    {
        $q = TownRankScan::withoutGlobalScope(SiteScope::class)->with('keyword')
            ->where('site_id', $site->id)->whereIn('status', ['complete', 'partial'])
            ->orderBy('scanned_at');

        $opt = trim((string) $this->option('keyword'));
        if ($opt !== '') {
            $base = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
            $ids = (clone $base)->where(fn ($w) => $w->where('id', $opt)->orWhereRaw('LOWER(query) = ?', [mb_strtolower($opt)]))->pluck('id');
            if ($ids->isEmpty()) {
                $ids = $base->where('query', 'like', "%{$opt}%")->pluck('id');
            }
            $q->whereIn('keyword_id', $ids->all());
        }

        return $q->get();
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
