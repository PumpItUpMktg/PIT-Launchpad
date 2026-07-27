<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "What is stalling the worker?" — a read-only x-ray of the database queue. The stalled-worker banner
 * says HOW MANY jobs are queued/failed; this says WHY: it reads the `failed_jobs` table (where every
 * failure records its exception) and groups the failures by job class + exception, most-frequent first,
 * so a systemic cause (fal out of credits → RenderImage 402, a revoked WP app-password → 401, a bad
 * migration) is obvious at a glance. Also reports the pending backlog + its age (a growing, ageing
 * backlog with zero recent failures usually means the worker process itself is DOWN, not the jobs).
 *
 * Read-only — changes nothing. Clear the failures with launchpad:reset-publish --flush-failed once the
 * cause is fixed.
 */
class QueueDiagnoseCommand extends Command
{
    protected $signature = 'launchpad:queue-diagnose
        {--limit=15 : Max distinct failure groups to show}
        {--recent : Also list the most recent individual failures with timestamps}
        {--flush : After reporting, DELETE all failed_jobs rows (clears the dead-job backlog + the stalled banner)}';

    protected $description = 'Diagnose the database queue — pending backlog + age, and failed jobs grouped by job class + exception (the WHY behind a stalled worker). --flush clears the dead jobs.';

    public function handle(): int
    {
        $pending = (int) DB::table('jobs')->count();
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $oldestMinutes = $oldestAvailableAt !== null ? (int) floor(max(0, time() - (int) $oldestAvailableAt) / 60) : 0;

        $this->line('<info>Pending</info>: '.$pending.' job(s)'
            .($pending > 0 ? " · oldest {$oldestMinutes}m old" : '')
            .($pending > 0 && $oldestMinutes >= 5 ? '  <comment>(ageing — the worker may be DOWN, not just failing jobs)</comment>' : ''));

        $failed = DB::table('failed_jobs')->orderByDesc('failed_at')->get();
        $this->line('<info>Failed</info>: '.$failed->count().' job(s).');

        if ($failed->isEmpty()) {
            $this->newLine();
            $this->comment($pending > 0
                ? 'No failures — jobs are queued but not being consumed. That points at the WORKER being down (no queue:work process), not the jobs. Start/restart the worker.'
                : 'Queue is clean — no pending or failed jobs.');

            return self::SUCCESS;
        }

        // Group by (job class, first line of the exception) — the systemic-cause signature.
        $groups = $failed
            ->groupBy(fn (object $row): string => $this->jobClass($row->payload).'  ✕  '.$this->exceptionHead($row->exception))
            ->map(fn ($rows, string $key): array => [
                'signature' => $key,
                'count' => $rows->count(),
                'latest' => $rows->max('failed_at'),
            ])
            ->sortByDesc('count')
            ->values();

        $this->newLine();
        $this->line('<info>Failures by cause</info> (most frequent first):');
        foreach ($groups->take((int) $this->option('limit')) as $g) {
            $this->line("  <comment>{$g['count']}×</comment>  {$g['signature']}");
            $this->line("        last: {$g['latest']}");
        }
        if ($groups->count() > (int) $this->option('limit')) {
            $this->line('  … and '.($groups->count() - (int) $this->option('limit')).' more distinct cause(s).');
        }

        if ($this->option('recent')) {
            $this->newLine();
            $this->line('<info>Most recent failures</info>:');
            foreach ($failed->take(15) as $row) {
                $this->line("  {$row->failed_at}  {$this->jobClass($row->payload)}  —  {$this->exceptionHead($row->exception)}");
            }
        }

        $this->newLine();

        // Clearing the dead-job backlog is what drops the "stalled" banner (it trips on any failed job).
        // These are GENERIC queue rows (generate/publish/populate), NOT publish-content-linked — so
        // reset-publish --flush-failed (content-scoped) can't touch them. Flush them here or via
        // Laravel's built-in `php artisan queue:flush`.
        if ($this->option('flush')) {
            $deleted = DB::table('failed_jobs')->delete();
            $this->info("Flushed {$deleted} failed job(s) — the dead-job backlog is cleared (the stalled banner clears with it). Fix the causes above so they don't recur.");

            return self::SUCCESS;
        }

        $this->comment('Fix the cause(s) above, then clear the dead-job backlog with: launchpad:queue-diagnose --flush   (or php artisan queue:flush).');

        return self::SUCCESS;
    }

    /** The job class from a serialized queue payload (e.g. "App\\Jobs\\RenderImage"). */
    private function jobClass(?string $payload): string
    {
        $data = json_decode((string) $payload, true);

        return is_array($data) && isset($data['displayName']) ? (string) $data['displayName'] : 'unknown job';
    }

    /** The first line of the recorded exception — the class + message, minus the stack trace. */
    private function exceptionHead(?string $exception): string
    {
        $first = trim((string) strtok((string) $exception, "\n"));

        return $first !== '' ? mb_strimwidth($first, 0, 160, '…') : 'no exception recorded';
    }
}
