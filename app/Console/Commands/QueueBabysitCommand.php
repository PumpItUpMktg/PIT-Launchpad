<?php

namespace App\Console\Commands;

use App\Operate\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The fallback that keeps work moving when no worker is listening.
 *
 * A queue worker is a process the platform owns, and on this deployment those processes keep disappearing —
 * killed without stopping, landing on a host whose environment points at another connection, or never
 * restarted at all. Every time, jobs stop dead: pages do not publish, scans do not collect, caches do not
 * warm, and the only sign is a backlog nobody is touching.
 *
 * The scheduler is the one process that has stayed up (it is what keeps dispatching the work that piles up),
 * so it can carry the load itself. Every minute this asks {@see QueueHealth} whether any lane holds an
 * ageing backlog with no live worker able to consume it; if so it runs an in-process worker over exactly
 * those lanes, bounded by {@see $signature}'s --max-time, and stops as soon as they are empty.
 *
 * It is insurance, not a replacement: a real worker is faster and runs continuously, and this one only wakes
 * when there is provably nobody else. When a worker is healthy this does nothing at all.
 */
class QueueBabysitCommand extends Command
{
    protected $signature = 'launchpad:queue-babysit
        {--stale=3 : Only step in once the oldest waiting job is this many minutes old}
        {--max-time=240 : Stop taking new jobs after this many seconds}
        {--memory=512 : Memory ceiling (MB) for the in-process worker}
        {--dry-run : Report what it would drain and start nothing}';

    protected $description = 'Drain any lane that has an ageing backlog and no live worker — the scheduler covering for a worker that died. Does nothing when a worker is healthy.';

    public function handle(QueueHealth $health): int
    {
        $stale = max(1, (int) $this->option('stale'));
        $lanes = array_values(array_filter(
            $health->lanes($stale),
            fn (array $lane): bool => $lane['pending'] > 0 && ! $lane['alive'] && $lane['oldest_minutes'] >= $stale,
        ));

        if ($lanes === []) {
            $this->info('Every lane with work has a live worker — nothing to cover for.');

            return self::SUCCESS;
        }

        $names = array_map(fn (array $l): string => $l['queue'], $lanes);
        $waiting = array_sum(array_map(fn (array $l): int => $l['pending'], $lanes));
        $oldest = max(array_map(fn (array $l): int => $l['oldest_minutes'], $lanes));
        $list = implode(',', $names);

        $this->warn(sprintf('No live worker on %s — %d job(s) waiting, oldest %dm. Draining in-process.', $list, $waiting, $oldest));
        Log::warning('Queue babysit: covering for an absent worker.', ['lanes' => $names, 'waiting' => $waiting, 'oldest_minutes' => $oldest]);

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run — nothing started.');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $this->call('queue:work', [
            'connection' => QueueHealth::appConnection(),
            '--queue' => $list,
            '--tries' => 3,
            '--stop-when-empty' => true,
            '--max-time' => max(30, (int) $this->option('max-time')),
            // The worker runs INSIDE the scheduler process, which is already carrying a booted framework —
            // at queue:work's 128MB default it would stop after a job or two and leave the lane behind.
            '--memory' => max(128, (int) $this->option('memory')),
        ]);

        $after = collect($health->lanes($stale))->whereIn('queue', $names)->sum('pending');
        $this->line(sprintf('Drained for %ds — %d job(s) still waiting.', (int) round(microtime(true) - $started), $after));
        Log::info('Queue babysit: pass finished.', ['lanes' => $names, 'remaining' => $after, 'seconds' => round(microtime(true) - $started, 1)]);

        return self::SUCCESS;
    }
}
