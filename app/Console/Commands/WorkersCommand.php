<?php

namespace App\Console\Commands;

use App\Operate\QueueHealth;
use Illuminate\Console\Command;

/**
 * The Queue page for the console: one line per lane (backlog + the live worker polling it) and one per
 * worker process (heartbeat, current job, tally, how it stopped). Read-only.
 */
class WorkersCommand extends Command
{
    protected $signature = 'launchpad:workers';

    protected $description = 'Show the queue lanes and every queue worker\'s heartbeat — what each is doing, and how a gone worker stopped. Read-only.';

    public function handle(QueueHealth $health): int
    {
        $snapshot = $health->snapshot();

        if ($snapshot['maintenance']) {
            $this->warn('The app is DOWN for maintenance — every queue:work daemon is paused. Workers keep heartbeating and consume nothing until `php artisan up`.');
            $this->newLine();
        }

        $this->line('<info>Lanes</info>');
        foreach ($snapshot['lanes'] as $lane) {
            $status = match (true) {
                $lane['busy'] !== null => "working {$lane['busy']}",
                $lane['alive'] => 'live, idle',
                $lane['down'] => 'DOWN — jobs waiting, no worker',
                $lane['pending'] > 0 => 'no worker listening',
                default => 'no worker, nothing waiting',
            };
            $this->line(sprintf('  %-22s waiting %-4d in flight %-3d oldest %-5s %s%s',
                $lane['queue'], $lane['pending'], $lane['reserved'], $lane['oldest_minutes'] > 0 ? $lane['oldest_minutes'].'m' : '—',
                $status, $lane['workers'] !== [] ? '  ['.implode(', ', $lane['workers']).']' : ''));
        }

        $this->newLine();
        $this->line('<info>Workers</info>');
        $workers = $health->workers();
        if ($workers === []) {
            $this->line('  none have reported yet — workers report from their first loop after this build; restart the background processes if they predate it.');
        }
        foreach ($workers as $w) {
            $state = match ($w['state']) {
                'working' => "working {$w['current_job']} on {$w['current_queue']} for {$w['job_seconds']}s",
                'idle' => 'live, idle',
                'stopped' => "stopped {$w['stopped_at']}: {$w['stop_reason']}",
                default => 'SILENT — no heartbeat, never stopped cleanly (killed, or never restarted)',
            };
            if ($w['whitespace_lanes'] !== []) {
                $this->warn(sprintf('  %s polls a lane name with a SPACE in it: "%s". queue:work splits --queue on commas without trimming, so that lane matches nothing and the worker waits on it forever. Remove the space from the process command.',
                    $w['worker_id'], implode('", "', $w['whitespace_lanes'])));
            }
            $this->line(sprintf('  %-28s [%s on %s]%s  seen %ds ago · %d done / %d failed · %d MB · started %s · %s',
                $w['worker_id'], $w['queues'], $w['connection'] !== '' ? $w['connection'] : 'unknown',
                $w['connection_ok'] ? '' : '  <comment>WRONG CONNECTION — this app enqueues on '.$snapshot['connection'].'</comment>',
                $w['seconds_since_seen'], $w['jobs_processed'], $w['jobs_failed'], $w['memory_mb'], $w['started_at'], $state));
        }

        if ($snapshot['failed'] > 0) {
            $this->newLine();
            $this->line("<comment>{$snapshot['failed']} failed job(s)</comment> — launchpad:queue-diagnose groups them by cause.");
        }

        return self::SUCCESS;
    }
}
