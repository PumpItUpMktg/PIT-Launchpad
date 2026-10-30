<?php

namespace App\Console\Commands;

use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\TownRankScan;
use App\Support\SiteFinder;
use App\TownRank\TownRankPoints;
use App\TownRank\TownRankReport;
use App\TownRank\TownRankScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Town Rank (§ Town Rank, PR 1): where the WEBSITE ranks for a keyword in every covered town, town by town —
 * "where does 'sump pump repair' rank in Hackettstown and its neighbors?". Report-first: the default run
 * prints the stored picture (latest scan per mode + the map-pack rank beside it) and spends nothing; --scan
 * posts a new DataForSEO sweep (dry-run + hard ceiling + confirmation, like the geo grid) and polls it in;
 * --collect finishes a sweep the poll window didn't.
 */
class TownRankCommand extends Command
{
    protected $signature = 'launchpad:town-rank {site : Site id, brand name, or domain (partial ok)}
        {--keyword= : One keyword (id or query substring); default: the site\'s grid keywords}
        {--mode=both : local | town | both — the query mode(s) to scan/report}
        {--town= : Filter the report to towns matching this text}
        {--since= : Compare against the newest finished scan at least this many days before the latest (e.g. 14 = two weeks ago) instead of the one just before it, and list the biggest movers}
        {--scan : Post a NEW scan (spends DataForSEO credits after confirmation)}
        {--collect : Collect results for scans still pending}
        {--dry-run : With --scan: print the plan (towns × modes → requests + cost) and spend nothing}
        {--yes : With --scan: skip the confirmation prompt (required where no prompt can be answered, e.g. a hosted Commands panel)}';

    /** Above this many towns the default report prints the summary only (the table needs --town). */
    private const TABLE_LIMIT = 80;

    protected $description = 'Where the website ranks for a keyword in every covered town (organic, per town) — report-first; --scan to pull fresh data.';

    public function handle(TownRankPoints $points, TownRankScanner $scanner, TownRankReport $report): int
    {
        $site = $this->site();
        if ($site === null) {
            return self::FAILURE;
        }
        $keywords = $this->keywords($site);
        if ($keywords->isEmpty()) {
            $this->error('No keyword to scan. Pass --keyword=<query> (a tracked keyword), or flag grid keywords on the site (is_grid_keyword).');

            return self::FAILURE;
        }
        $modes = $this->modes();
        if ($modes === []) {
            $this->error('--mode must be local, town, or both.');

            return self::FAILURE;
        }

        if ((bool) $this->option('scan')) {
            $exit = $this->scan($site, $keywords, $modes, $points, $scanner);
            if ($exit !== self::SUCCESS || (bool) $this->option('dry-run')) {
                return $exit;
            }
        } elseif ((bool) $this->option('collect')) {
            $this->collect($site, $keywords, $modes, $scanner);
        }

        $since = $this->option('since') !== null && (int) $this->option('since') > 0 ? (int) $this->option('since') : null;
        foreach ($keywords as $keyword) {
            $data = $report->forKeyword($site, $keyword, $since);
            $this->printReport($data, $modes);
            if ($since !== null) {
                $this->printMovers($data, $modes, $since);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Keyword>  $keywords
     * @param  list<string>  $modes
     */
    private function scan(Site $site, Collection $keywords, array $modes, TownRankPoints $points, TownRankScanner $scanner): int
    {
        $towns = $points->forSite($site);
        $requests = count($towns) * count($modes) * $keywords->count();
        $costPer = TownRankScanner::costPerRequest();
        $ceiling = max(0, (int) config('launchpad.town_rank.request_ceiling', 2000));
        $withPage = count(array_filter($towns, fn (array $t): bool => $t['page_url'] !== null));

        $this->info($site->brand_name ?: (string) $site->id);
        $this->table(['Metric', 'Value'], [
            ['Covered towns (geocoded)', count($towns)." ({$withPage} with a published page)"],
            ['Keywords', $keywords->pluck('query')->implode(', ')],
            ['Modes', implode(', ', $modes)],
            ['DataForSEO requests', number_format($requests).' (1 per town × mode × keyword)'],
            ['Estimated cost', '$'.number_format($requests * $costPer, 2)],
            ['Hard request ceiling', number_format($ceiling)],
        ]);

        if ($towns === []) {
            $this->comment('Nothing to scan — the site has no geocoded coverage towns yet (a location needs served counties or assigned towns).');

            return self::SUCCESS;
        }
        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — no API calls, nothing spent.');

            return self::SUCCESS;
        }
        if ($ceiling > 0 && $requests > $ceiling) {
            $this->error("ABORTED — {$requests} requests exceeds the hard ceiling ({$ceiling}). Narrow --keyword/--mode or raise LAUNCHPAD_TOWN_RANK_REQUEST_CEILING.");

            return self::FAILURE;
        }
        if (! (bool) $this->option('yes')) {
            if (! $this->input->isInteractive()) {
                $this->error('Non-interactive run — nothing posted. Re-run with --yes to post the '.number_format($requests).' request(s) (~$'.number_format($requests * $costPer, 2).').');

                return self::FAILURE;
            }
            if (! $this->confirm("Post {$requests} DataForSEO request(s) (~\$".number_format($requests * $costPer, 2).')?', false)) {
                $this->comment('Cancelled.');

                return self::SUCCESS;
            }
        }

        $scans = [];
        foreach ($keywords as $keyword) {
            foreach ($modes as $mode) {
                try {
                    $scan = $scanner->post($site, $keyword, $mode);
                    if ($scan !== null) {
                        $scans[] = $scan;
                        $this->line("  <info>✓</info> posted {$keyword->query} · {$mode} → scan {$scan->id} ({$scan->points_count} towns)");
                    }
                } catch (Throwable $e) {
                    $this->line("  <error>✗</error> {$keyword->query} · {$mode}: ".mb_strimwidth($e->getMessage(), 0, 160, '…'));
                }
            }
        }

        $this->poll($scans, $scanner);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Keyword>  $keywords
     * @param  list<string>  $modes
     */
    private function collect(Site $site, Collection $keywords, array $modes, TownRankScanner $scanner): void
    {
        $pending = TownRankScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('status', 'pending')
            ->whereIn('keyword_id', $keywords->pluck('id')->all())->whereIn('mode', $modes)
            ->orderBy('scanned_at')->get()->all();
        if ($pending === []) {
            $this->comment('No pending scans to collect.');

            return;
        }
        $this->poll($pending, $scanner);
    }

    /**
     * Poll the standard queue for the posted scans until complete or the attempt ceiling; what's left stays
     * pending for --collect.
     *
     * @param  list<TownRankScan>  $scans
     */
    private function poll(array $scans, TownRankScanner $scanner): void
    {
        if ($scans === []) {
            return;
        }
        $interval = max(0, (int) config('launchpad.town_rank.poll_interval_seconds', 5));
        $attempts = max(1, (int) config('launchpad.town_rank.poll_max_attempts', 24));

        $this->line('  Collecting results…');
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0 && $interval > 0) {
                sleep($interval);
            }
            $open = 0;
            foreach ($scans as $scan) {
                $scan->refresh();
                if ($scan->status !== 'pending') {
                    continue;
                }
                $scanner->collectPending($scan, PHP_INT_MAX);
                $scan->refresh();
                if ($scan->status === 'pending') {
                    $open++;
                }
            }
            if ($open === 0) {
                break;
            }
        }

        foreach ($scans as $scan) {
            $scan->refresh();
            $collected = $scan->points()->whereNotNull('collected_at')->count();
            $this->line("  {$scan->mode}: {$collected}/{$scan->points_count} towns collected · {$scan->status}"
                .($scan->status === 'pending' ? ' — re-run with --collect to finish' : ''));
        }
    }

    /**
     * @param  array{keyword: string, scans: array<string, array{id: string, status: string, scanned_at: string|null, points: int, collected: int, found: int, previous_scanned_at: string|null}|null>, rows: list<array<string, mixed>>, summary: array<string, array{top3: int, page1: int, page2: int, beyond: int, not_found: int, pending: int, up: int, down: int, new: int, lost: int, same: int}>}  $data
     * @param  list<string>  $modes
     */
    private function printReport(array $data, array $modes): void
    {
        $this->newLine();
        $this->info("Keyword: {$data['keyword']}");

        foreach ($modes as $mode) {
            $scan = $data['scans'][$mode];
            $label = $mode === TownRankScan::MODE_LOCAL ? 'local (searched from each town)' : 'town query ("keyword town ST")';
            if ($scan === null) {
                $this->line("  {$label}: no scan yet — run with --scan --dry-run to see the plan.");

                continue;
            }
            $s = $data['summary'][$mode];
            $this->line(sprintf(
                '  %s: %s %s · %d towns · top-3 %d · page-1 %d · page-2 %d · beyond %d · not found %d%s%s',
                $label, $scan['status'], (string) $scan['scanned_at'], $scan['points'],
                $s['top3'], $s['page1'], $s['page2'], $s['beyond'], $s['not_found'],
                $s['pending'] > 0 ? " · pending {$s['pending']}" : '',
                $scan['previous_scanned_at'] !== null ? sprintf(' · vs %s: ▲%d ▼%d new %d lost %d', $scan['previous_scanned_at'], $s['up'], $s['down'], $s['new'], $s['lost']) : '',
            ));
        }

        $filter = mb_strtolower(trim((string) $this->option('town')));
        $rows = array_values(array_filter($data['rows'], fn (array $r): bool => $filter === '' || str_contains(mb_strtolower((string) $r['label']), $filter)));
        if ($rows === []) {
            $this->comment($filter !== '' ? "  No covered town matches [{$filter}]." : '  No covered towns.');

            return;
        }
        // A whole-site table is hundreds of rows: after a scan/collect, or on a big footprint, print only the
        // summary unless the operator sliced it with --town.
        if ($filter === '' && (count($rows) > self::TABLE_LIMIT || (bool) $this->option('scan') || (bool) $this->option('collect'))) {
            $this->comment('  '.count($rows).' covered towns — pass --town=<name> to see a slice of the table.');

            return;
        }

        $showLocal = in_array(TownRankScan::MODE_LOCAL, $modes, true);
        $showTown = in_array(TownRankScan::MODE_TOWN_QUERY, $modes, true);
        $headers = ['Town', 'Pop', 'Page'];
        if ($showLocal) {
            $headers[] = 'Local';
        }
        if ($showTown) {
            $headers[] = 'Town query';
        }
        $headers[] = 'Map pack';
        $headers[] = 'Ranking URL';

        $this->table($headers, array_map(function (array $r) use ($showLocal, $showTown): array {
            $line = [
                $r['label'].($r['state'] !== null ? ", {$r['state']}" : ''),
                $r['population'] > 0 ? number_format((int) $r['population']) : '—',
                $r['page_url'] !== null ? '✓' : '—',
            ];
            if ($showLocal) {
                $line[] = self::cell($r['local_rank'], (string) $r['local_state']);
            }
            if ($showTown) {
                $line[] = self::cell($r['town_rank'], (string) $r['town_state']);
            }
            $line[] = $r['map_rank'] !== null ? '#'.$r['map_rank'] : '—';
            $url = $r['local_url'] ?? $r['town_url'];
            $line[] = is_string($url) ? Str::limit(preg_replace('#^https?://#', '', $url) ?? $url, 48) : '';

            return $line;
        }, $rows));
    }

    /**
     * The comparison digest for --since: per mode, where the towns sat THEN vs NOW by bucket, the movement
     * counts, and the biggest movers each way (new / up first, lost / down first) — the pasteable answer to
     * "how did we move in the last N days".
     *
     * @param  array{keyword: string, scans: array<string, array{scanned_at: string|null, previous_scanned_at: string|null}|null>, rows: list<array<string, mixed>>, summary: array<string, array<string, int>>}  $data
     * @param  list<string>  $modes
     */
    private function printMovers(array $data, array $modes, int $since): void
    {
        foreach ($modes as $mode) {
            $scan = $data['scans'][$mode];
            if ($scan === null) {
                continue;
            }
            $prefix = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town';
            $label = $mode === TownRankScan::MODE_LOCAL ? 'local' : 'town query';
            $this->newLine();
            if ($scan['previous_scanned_at'] === null) {
                $this->comment("  {$label}: no finished scan at least {$since} days before {$scan['scanned_at']} — nothing to compare against yet.");

                continue;
            }
            $this->info("  {$label}: {$scan['previous_scanned_at']} → {$scan['scanned_at']}");

            $then = ['top3' => 0, 'page1' => 0, 'page2' => 0, 'beyond' => 0, 'not_found' => 0];
            $now = $then;
            $movers = [];
            foreach ($data['rows'] as $r) {
                $change = $r["{$prefix}_change"];
                if ($change === null) {
                    continue; // pending / unscanned this time — not comparable
                }
                $rank = $r["{$prefix}_rank"];
                $prev = $r["{$prefix}_prev_rank"];
                $then[self::bucket(is_int($prev) ? $prev : null)]++;
                $now[self::bucket(is_int($rank) ? $rank : null)]++;
                if ($change !== 'same') {
                    $movers[] = ['town' => $r['label'].($r['state'] !== null ? ", {$r['state']}" : ''), 'then' => $prev, 'now' => $rank, 'change' => $change];
                }
            }
            $this->table(['', 'Top 3', 'Page 1', 'Page 2', 'Beyond', 'Not found'], [
                ['Then', $then['top3'], $then['page1'], $then['page2'], $then['beyond'], $then['not_found']],
                ['Now', $now['top3'], $now['page1'], $now['page2'], $now['beyond'], $now['not_found']],
            ]);
            $s = $data['summary'][$mode];
            $this->line(sprintf('  ▲ up %d · ▼ down %d · new %d · lost %d · unchanged %d', $s['up'], $s['down'], $s['new'], $s['lost'], $s['same']));

            // Biggest gains: new rankings first (from nothing), then the largest rank improvements.
            $gains = array_values(array_filter($movers, fn (array $m): bool => in_array($m['change'], ['new', 'up'], true)));
            usort($gains, fn (array $a, array $b): int => [$a['change'] === 'new' ? 0 : 1, ($a['now'] ?? 999) - ($a['then'] ?? 999)] <=> [$b['change'] === 'new' ? 0 : 1, ($b['now'] ?? 999) - ($b['then'] ?? 999)]);
            $losses = array_values(array_filter($movers, fn (array $m): bool => in_array($m['change'], ['lost', 'down'], true)));
            usort($losses, fn (array $a, array $b): int => [$a['change'] === 'lost' ? 0 : 1, ($b['now'] ?? 999) - ($b['then'] ?? 999)] <=> [$b['change'] === 'lost' ? 0 : 1, ($a['now'] ?? 999) - ($a['then'] ?? 999)]);
            foreach ([['Biggest gains', $gains], ['Biggest losses', $losses]] as [$heading, $list]) {
                if ($list === []) {
                    continue;
                }
                $this->line("  {$heading}:");
                foreach (array_slice($list, 0, 10) as $m) {
                    $this->line(sprintf('    %-34s %s → %s', Str::limit($m['town'], 32), $m['then'] === null ? 'not found' : '#'.$m['then'], $m['now'] === null ? 'not found' : '#'.$m['now']));
                }
            }
        }
    }

    private static function bucket(?int $rank): string
    {
        return match (true) {
            $rank === null => 'not_found',
            $rank <= 3 => 'top3',
            $rank <= 10 => 'page1',
            $rank <= 20 => 'page2',
            default => 'beyond',
        };
    }

    private static function cell(mixed $rank, string $state): string
    {
        return match ($state) {
            'unscanned' => '',
            'pending' => '…',
            'not_found' => 'not found',
            default => '#'.(int) $rank,
        };
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

    /**
     * The keywords to scan/report: --keyword by id or EXACT query first (so naming one keyword scans one
     * keyword — "sump pump service" must not also pull in "commercial sump pump service"), falling back to a
     * substring match only when nothing matches exactly; no option → the site's grid keywords.
     *
     * @return Collection<int, Keyword>
     */
    private function keywords(Site $site): Collection
    {
        $opt = trim((string) $this->option('keyword'));
        $base = Keyword::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);

        if ($opt === '') {
            return $base->where('is_grid_keyword', true)->orderBy('query')->get();
        }

        $exact = (clone $base)
            ->where(fn ($w) => $w->where('id', $opt)->orWhereRaw('LOWER(query) = ?', [mb_strtolower($opt)]))
            ->orderBy('query')
            ->get();
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return $base->where('query', 'like', "%{$opt}%")->orderBy('query')->get();
    }

    /** @return list<string> */
    private function modes(): array
    {
        return match (mb_strtolower(trim((string) $this->option('mode')))) {
            'local' => [TownRankScan::MODE_LOCAL],
            'town', 'town_query' => [TownRankScan::MODE_TOWN_QUERY],
            'both', '' => TownRankScan::MODES,
            default => [],
        };
    }
}
