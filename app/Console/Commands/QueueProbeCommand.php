<?php

namespace App\Console\Commands;

use App\Operate\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * "The worker says it is alive, the backlog says nothing is moving — who is right?"
 *
 * Read-only. It answers from the worker's own point of view rather than by inference: where the jobs are
 * written (connection, driver, database, table), whether the worker loop is even allowed to run right now
 * (maintenance mode pauses every `queue:work` daemon while it keeps heartbeating — the exact shape of a
 * "live but idle" worker over a growing backlog), and — the ground truth — the RESERVE QUERY the database
 * driver runs per lane, reproduced exactly (Illuminate\Queue\DatabaseQueue::getNextAvailableJob), so the
 * output is the row a worker would pick up next, or the reason there isn't one.
 *
 * Reserves nothing and changes nothing.
 */
class QueueProbeCommand extends Command
{
    protected $signature = 'launchpad:queue-probe {--queue= : Only this lane (default: every lane holding jobs)}';

    protected $description = 'Ask the queue what a worker would see right now: the store, maintenance state, and the exact next job each lane would hand out. Read-only.';

    public function handle(QueueHealth $health): int
    {
        $connection = QueueHealth::appConnection();
        $driver = (string) config("queue.connections.{$connection}.driver", '');
        $dbConnection = (string) (config("queue.connections.{$connection}.connection") ?? config('database.default'));
        $table = (string) config("queue.connections.{$connection}.table", 'jobs');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 90);
        $database = (string) config("database.connections.{$dbConnection}.database", '');

        $this->line('<info>Store</info>');
        $this->line("  queue connection : {$connection} ({$driver})");
        $this->line("  database         : {$dbConnection}".($database !== '' ? "  [{$database}]" : ''));
        $this->line("  table            : {$table}");
        $this->line("  retry_after      : {$retryAfter}s");
        if ($driver !== 'database') {
            $this->warn("  This probe reads the database driver's tables; the app is configured for [{$driver}], so a worker polls somewhere this cannot see.");
        }

        $down = App::isDownForMaintenance();
        $this->newLine();
        $this->line('<info>Worker loop</info>');
        $this->line('  maintenance mode : '.($down ? 'DOWN' : 'up'));
        if ($down) {
            $this->warn('  Every queue:work daemon PAUSES while the app is down (it keeps heartbeating and consumes nothing — a "live but idle" worker over a growing backlog). Bring the app up, or run the worker with --force.');
        }

        $now = Carbon::now()->getTimestamp();
        $expiration = Carbon::now()->subSeconds($retryAfter)->getTimestamp();
        $only = trim((string) $this->option('queue'));
        $lanes = $only !== ''
            ? [$only]
            : DB::connection($dbConnection)->table($table)->distinct()->orderBy('queue')->pluck('queue')->map(fn ($q): string => (string) $q)->all();

        $this->newLine();
        $this->line('<info>What each lane would hand a worker right now</info>');
        if ($lanes === []) {
            $this->line('  no lane holds a job.');
        }

        foreach ($lanes as $lane) {
            $base = DB::connection($dbConnection)->table($table)->where('queue', $lane);
            $total = (clone $base)->count();

            // The driver's own predicate, reproduced: unreserved and due, OR reserved past retry_after.
            $next = (clone $base)
                ->where(function ($query) use ($now, $expiration): void {
                    $query->where(function ($q) use ($now): void {
                        $q->whereNull('reserved_at')->where('available_at', '<=', $now);
                    })->orWhere(function ($q) use ($expiration): void {
                        $q->where('reserved_at', '<=', $expiration);
                    });
                })
                ->orderBy('id')
                ->first();

            $this->line("  <comment>{$lane}</comment>  {$total} row(s)");
            if ($next !== null) {
                $age = (int) floor(max(0, $now - (int) $next->available_at) / 60);
                $this->line(sprintf('    → would reserve job #%s, attempts %d, available %dm ago%s',
                    (string) $next->id, (int) $next->attempts, $age,
                    $next->reserved_at !== null ? ' (a reservation that outlived retry_after — its worker died holding it)' : ''));
                $this->line('    So the rows are reservable. A worker on this lane that is not taking them is not reading this table:'
                    .' check its --queue list, its QUEUE_CONNECTION, and that the process is actually running (launchpad:workers).');
            } elseif ($total > 0) {
                $delayed = (clone $base)->whereNull('reserved_at')->where('available_at', '>', $now)->count();
                $heldRows = (clone $base)->whereNotNull('reserved_at')->where('reserved_at', '>', $expiration)->count();
                $this->line('    → nothing reservable: '
                    .($delayed > 0 ? "{$delayed} still delayed (available_at in the future)" : '')
                    .($delayed > 0 && $heldRows > 0 ? ', ' : '')
                    .($heldRows > 0 ? "{$heldRows} held by a live reservation (returns within {$retryAfter}s of its worker stopping)" : '')
                    .($delayed === 0 && $heldRows === 0 ? 'the rows match neither predicate — inspect them directly' : '').'.');
            } else {
                $this->line('    → empty.');
            }
        }

        // The size the framework itself reports, as a cross-check on everything above.
        $this->newLine();
        $this->line('<info>Framework-reported size</info>');
        foreach ($lanes as $lane) {
            try {
                $this->line(sprintf('  %-22s %d', $lane, Queue::connection($connection)->size($lane)));
            } catch (\Throwable $e) {
                app(ExceptionHandler::class)->report($e);
                $this->line(sprintf('  %-22s unreadable: %s', $lane, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->comment('launchpad:workers shows which processes are alive and what each one polls.');

        return self::SUCCESS;
    }
}
