<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Operator\Coverage\ReworkCandidates;
use Illuminate\Console\Command;

/**
 * The rework candidates (§ Indexing): pages Google crawled and declined, waiting past the rework window,
 * each with the three-way verdict (thin → rework, duplicate → merge, off-topic → drop). `--execute`
 * queues the rework for every `thin` one (town pages included — never pruned); merges and drops stay the
 * operator's explicit choice on the Indexing board.
 */
class ReworkStuckCommand extends Command
{
    protected $signature = 'launchpad:rework-stuck
        {--site= : Site id or brand name (required)}
        {--execute : queue the rework (regenerate with the index brief) for every thin candidate}
        {--limit= : with --execute, queue at most this many}';

    protected $description = 'Report (and with --execute, rework) the pages Google crawled but declined to index';

    public function handle(ReworkCandidates $rework): int
    {
        $arg = $this->option('site');
        $site = is_string($arg) && $arg !== '' ? Site::withoutGlobalScopes()->where('id', $arg)->orWhere('brand_name', $arg)->first() : null;
        if ($site === null) {
            $this->error('Pass --site=<id or brand name>.');

            return self::FAILURE;
        }

        $report = $rework->for($site);
        $this->info(sprintf('Rework candidates — %s: crawled, not indexed, waiting %d+ days', $site->brand_name, $report['rework_days']));
        foreach ($report['by_verdict'] as $verdict => $n) {
            $this->line(sprintf('  %-10s %d', $verdict, $n));
        }
        if ($report['rows'] === []) {
            $this->line('Nothing to rework.');

            return self::SUCCESS;
        }
        $this->table(['Page', 'Kind', 'Days', 'Verdict', 'Why', 'Reworked'], array_map(fn (array $r): array => [
            mb_substr($r['title'], 0, 40), $r['is_town'] ? 'town' : $r['kind'], $r['days_waiting'] ?? '—', $r['verdict'],
            mb_substr($r['reason'], 0, 70), $r['rework'] !== null && $r['rework']['applied_at'] !== null ? substr($r['rework']['applied_at'], 0, 10) : ($r['rework'] !== null ? 'queued' : ''),
        ], $report['rows']));

        if (! $this->option('execute')) {
            $this->line('Dry run — add --execute to queue the rework for the thin ones (merges and drops are decided on the Indexing board).');

            return self::SUCCESS;
        }

        $ids = array_column(array_filter($report['rows'], fn (array $r): bool => $r['verdict'] === ReworkCandidates::THIN && ($r['rework'] === null || $r['rework']['applied_at'] !== null)), 'content_id');
        $limit = $this->option('limit');
        if (is_numeric($limit) && (int) $limit > 0) {
            $ids = array_slice($ids, 0, (int) $limit);
        }
        $result = $rework->apply($site, $ids);
        $this->info(sprintf('Queued the rework for %d page(s). They return to the review queue as they draft; approve to re-push, then re-check indexing in a few weeks.', $result['queued']));

        return self::SUCCESS;
    }
}
